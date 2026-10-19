<?php

namespace App\Support\StatusHealth;

use App\Http\Controllers\Data\Concerns\CompressesJson;
use App\Http\Controllers\Data\QueryController;
use App\Http\Controllers\Data\SummaryController;
use App\Models\Channel;
use App\Models\CuratorAssignment;
use App\Models\Event;
use App\Models\PublicSubmission;
use App\Models\Source;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The Mojave River Valley checks and metrics for the status dashboard's health endpoint (contract status-health/1, section 9).
 * StatusHealthController::groups() builds its groups from these methods; the controller itself is the dashboard's reference file
 * and is not edited here, so everything this property measures lives in this class, where it can be tested with a fixed clock.
 *
 * Every check returns key, label, status, value, unit and message. Times are UTC. Each source's timestamps are converted from the
 * zone that source is known to use:
 *   live_conditions_log.observed_at   naive Pacific time, as the NWS station reports it (a DATETIME column)
 *   weather_daily.date, air_quality_daily.date   a Pacific calendar day (DATE columns)
 *   climate_fetch_progress.completed_at   a UTC string written by the job itself (the column is a TIMESTAMP, but the job gives it
 *                                         an explicit value, so it round-trips as written; UNIX_TIMESTAMP() would be off by the
 *                                         database server's zone offset)
 *   sources.last_success_at and the other Laravel tables   the application's own zone (config app.timezone)
 */
final class MrvChecks
{
    public const PACIFIC = 'America/Los_Angeles';

    /** Equivalent Open-Meteo calls one climate city-and-model pair costs (CLIMATE_EQUIV_CALLS_PER_PAIR in api/cron/climate_lib.php). */
    private const CLIMATE_CALLS_PER_PAIR = 480;

    /** Collectors that ingest.* commands run; only ICS is scheduled, the other two are run by hand. */
    private const INGESTED_COLLECTORS = ['ics', 'tribe_events', 'squarespace_events'];

    public function __construct(
        private readonly CarbonImmutable $now,
        private readonly ?string $logDir = null,
        private readonly ?string $statusDir = null,
    ) {}

    // ---------------------------------------------------------------------------------------------
    // Time

    /**
     * A naive Pacific time (as live_conditions_log stores it) as a UTC instant. The hour that repeats when the clocks go back
     * (01:00 to 01:59 on the first Sunday of November) is read as its second occurrence, standard time: an observation can then look
     * up to an hour newer than it is, never older, so the fall-back night cannot cause a false alarm.
     */
    public static function pacificToUtc(string $local): CarbonImmutable
    {
        $zone = new DateTimeZone(self::PACIFIC);
        $first = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $local, $zone);
        $later = CarbonImmutable::createFromTimestampUTC($first->getTimestamp() + 3600)->setTimezone($zone);

        return ($later->format('Y-m-d H:i:s') === $local ? $later : $first)->utc();
    }

    /** A UTC string, as the jobs themselves write them. */
    public static function utc(string $value): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', substr($value, 0, 19), 'UTC');
    }

    /** A timestamp the Laravel application wrote (it writes in config app.timezone). */
    public static function appTime(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, (string) config('app.timezone', 'UTC'))->utc();
    }

    /** Whole days from one calendar date (Y-m-d) to another. */
    public static function daysBetween(string $from, string $to): int
    {
        $a = CarbonImmutable::createFromFormat('!Y-m-d', substr($from, 0, 10), 'UTC');
        $b = CarbonImmutable::createFromFormat('!Y-m-d', substr($to, 0, 10), 'UTC');

        return (int) round(($b->getTimestamp() - $a->getTimestamp()) / 86400);
    }

    private function pacificNow(): CarbonImmutable
    {
        return $this->now->setTimezone(self::PACIFIC);
    }

    private function hoursSince(CarbonImmutable $then): float
    {
        return max(0, $this->now->getTimestamp() - $then->getTimestamp()) / 3600;
    }

    /** Wraps one check so that a failure makes that check unknown and leaves the others in its group alone. */
    public function guard(string $key, string $label, callable $check): array
    {
        try {
            return $check();
        } catch (Throwable $e) {
            return ['key' => $key, 'label' => $label, 'status' => 'unknown', 'message' => 'Check failed to run'];
        }
    }

    private function weather(): Connection
    {
        return DB::connection('weather');
    }

    // ---------------------------------------------------------------------------------------------
    // Weather

    /** Newest NWS observation across the cities that have a station. Stations report every 20 to 60 minutes. */
    public function liveConditions(): array
    {
        $base = ['key' => 'weather_freshness_live_conditions', 'label' => 'Live conditions data'];
        $newest = $this->weather()->table('live_conditions_log')->max('observed_at');
        if ($newest === null) {
            return $base + ['status' => 'critical', 'message' => 'No live observations are stored'];
        }
        $hours = $this->hoursSince(self::pacificToUtc((string) $newest));
        $status = $hours > 2 ? 'critical' : ($hours > 1 ? 'warning' : 'healthy');

        return $base + [
            'status' => $status,
            'value' => round($hours, 1),
            'unit' => 'hours',
            'message' => $status === 'healthy' ? null : 'Newest live observation is '.$this->ago($hours).' old',
        ];
    }

    /** Cities that reported in the last 7 days but not in the last 3 hours (a station that went offline). */
    public function liveCitiesStale(): array
    {
        $base = ['key' => 'weather_live_cities_stale', 'label' => 'Live conditions by city'];
        $since = $this->pacificNow()->subDays(7)->format('Y-m-d H:i:s');
        $rows = $this->weather()->table('live_conditions_log')
            ->where('observed_at', '>=', $since)
            ->selectRaw('city_id, MAX(observed_at) as newest')
            ->groupBy('city_id')
            ->get();
        if ($rows->isEmpty()) {
            return $base + ['status' => 'inactive', 'message' => 'No city has reported in 7 days'];
        }
        $stale = $rows->filter(fn ($r) => $this->hoursSince(self::pacificToUtc((string) $r->newest)) > 3)->count();
        $total = $rows->count();
        $status = $stale === 0 ? 'healthy' : ($stale * 2 >= $total ? 'critical' : 'warning');

        return $base + [
            'status' => $status,
            'value' => $stale,
            'unit' => 'count',
            'message' => $stale === 0 ? null : "{$stale} of {$total} cities have not reported in 3 hours",
        ];
    }

    /** Days between today (Pacific) and the oldest "newest date" among the 36 named cities. */
    public function dailyWeather(): array
    {
        return $this->dailyLag('weather_daily', 'weather_freshness_daily_weather', 'Daily weather data', 3, 5);
    }

    public function airQuality(): array
    {
        return $this->dailyLag('air_quality_daily', 'weather_freshness_air_quality', 'Air quality data', 2, 4);
    }

    /**
     * Daily tables carry a Pacific calendar date. The newest date is normally one to two days behind today (the archive that
     * feeds weather_daily lags about two days; air quality about one), so the thresholds are in days behind, not hours old.
     */
    private function dailyLag(string $table, string $key, string $label, int $warnAbove, int $criticalAbove): array
    {
        $base = ['key' => $key, 'label' => $label];
        $rows = $this->weather()->table($table.' as t')
            ->join('cities as c', 'c.id', '=', 't.city_id')
            ->where('c.place_type', 'city')
            ->selectRaw('t.city_id as city_id, MAX(t.date) as newest')
            ->groupBy('t.city_id')
            ->get();
        if ($rows->isEmpty()) {
            return $base + ['status' => 'critical', 'message' => 'No rows are stored for the named cities'];
        }
        $today = $this->pacificNow()->format('Y-m-d');
        $lags = $rows->map(fn ($r) => max(0, self::daysBetween((string) $r->newest, $today)));
        $worst = (int) $lags->max();
        $behind = $lags->filter(fn ($l) => $l > $warnAbove)->count();
        $status = $worst > $criticalAbove ? 'critical' : ($worst > $warnAbove ? 'warning' : 'healthy');

        return $base + [
            'status' => $status,
            'value' => $worst,
            'unit' => 'days',
            'message' => $status === 'healthy' ? null : "{$behind} of {$rows->count()} cities are more than {$warnAbove} days behind; the oldest is {$worst} days behind",
        ];
    }

    /** Climate snapshot: quiet while no snapshot is open, otherwise the age of the newest completed city-and-model pair. */
    public function climateSnapshot(): array
    {
        $base = ['key' => 'weather_freshness_climate_snapshot', 'label' => 'Climate snapshot data'];
        $snapshots = $this->weather()->table('climate_snapshots')
            ->orderByDesc('snapshot')
            ->get(['snapshot', 'pairs_expected']);
        $open = null;
        foreach ($snapshots as $snapshot) {
            $progress = $this->weather()->table('climate_fetch_progress')->where('snapshot', $snapshot->snapshot);
            if ((clone $progress)->count() < (int) $snapshot->pairs_expected) {
                $open = [$snapshot, $progress];
                break;
            }
        }
        if ($open === null) {
            return $base + ['status' => 'inactive', 'message' => 'No snapshot open'];
        }
        [$snapshot, $progress] = $open;
        $done = (clone $progress)->count();
        $latest = $progress->max('completed_at');
        $since = $latest !== null ? self::utc((string) $latest) : self::utc($snapshot->snapshot.' 00:00:00');
        $hours = $this->hoursSince($since);
        // A budget of a few pairs a day completes them together, so a day between completions is normal.
        $status = $hours > 72 ? 'critical' : ($hours > 36 ? 'warning' : 'healthy');

        return $base + [
            'status' => $status,
            'value' => round($hours, 1),
            'unit' => 'hours',
            'message' => $status === 'healthy' ? null : "Snapshot {$snapshot->snapshot} is open ({$done} of {$snapshot->pairs_expected} pairs); the last pair finished ".$this->ago($hours).' ago',
        ];
    }

    /** Share of the last 24 hours of live observations with no temperature, and cities that went silent. */
    public function weatherCompleteness(): array
    {
        $base = ['key' => 'weather_completeness', 'label' => 'Live data completeness'];
        $pacific = $this->pacificNow();
        $dayStart = $pacific->subDay()->format('Y-m-d H:i:s');
        $weekStart = $pacific->subDays(7)->format('Y-m-d H:i:s');

        $stats = $this->weather()->table('live_conditions_log')
            ->where('observed_at', '>=', $dayStart)
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN temp_f IS NULL THEN 1 ELSE 0 END) as missing')
            ->first();
        $total = (int) ($stats->total ?? 0);
        if ($total === 0) {
            return $base + ['status' => 'critical', 'message' => 'No live observations in the last 24 hours'];
        }
        $share = round(100 * (int) $stats->missing / $total, 1);

        $recent = $this->weather()->table('live_conditions_log')->where('observed_at', '>=', $dayStart)->distinct()->pluck('city_id');
        $week = $this->weather()->table('live_conditions_log')->where('observed_at', '>=', $weekStart)->distinct()->pluck('city_id');
        $silent = $week->diff($recent)->count();

        $status = $share > 20 ? 'critical' : (($share > 5 || $silent > 0) ? 'warning' : 'healthy');
        $parts = [];
        if ($share > 5) {
            $parts[] = "{$share}% of observations have no temperature";
        }
        if ($silent > 0) {
            $parts[] = $silent.' '.($silent === 1 ? 'city has' : 'cities have').' no observation in 24 hours';
        }

        return $base + [
            'status' => $status,
            'value' => $share,
            'unit' => 'percent',
            'message' => $parts === [] ? null : ucfirst(implode('; ', $parts)),
        ];
    }

    /** Open-Meteo calls the tracked jobs spent today (UTC day): the backfill and air quality budgets, and climate pairs. */
    public function openMeteoCallsToday(): ?float
    {
        $today = $this->now->format('Y-m-d');
        $calls = 0.0;
        $found = false;
        foreach (['backfill_budget', 'airquality_budget'] as $table) {
            try {
                $spent = $this->weather()->table($table)->where('budget_date', $today)->value('equivalent_calls_spent');
                if ($spent !== null) {
                    $calls += (float) $spent;
                }
                $found = true;
            } catch (Throwable $e) {
                // A table that does not exist contributes nothing.
            }
        }
        try {
            $pairs = $this->weather()->table('climate_fetch_progress')->where('completed_at', '>=', $today.' 00:00:00')->count();
            $calls += $pairs * self::CLIMATE_CALLS_PER_PAIR;
            $found = true;
        } catch (Throwable $e) {
        }

        return $found ? $calls : null;
    }

    /**
     * Open-Meteo runs the daily quota stopped today (UTC date), across the four scripts that can stop on it. The scripts add to
     * <status folder>/counter_quota_stops.json (api/cron/job_status_lib.php, job_counter_increment()). A metric with no threshold.
     */
    public function quotaStopsMetric(): ?array
    {
        return $this->dailyCounterMetric('quota_stops_today', 'Open-Meteo runs stopped by the daily quota today', 'counter_quota_stops.json', null);
    }

    /**
     * Groundwater runs the USGS rate limit stopped today (UTC date). groundwater_update.php keeps the counter in the "counts"
     * object of its own status file, groundwater.json. A metric with no threshold.
     */
    public function usgsThrottledMetric(): ?array
    {
        return $this->dailyCounterMetric('usgs_throttled_today', 'Groundwater runs stopped by the USGS rate limit today', 'groundwater.json', 'usgs_throttled');
    }

    /**
     * Today's value of a daily counter written by a cron script: {"date":"Y-m-d","count":n,"updated_at":"...Z"}, with the date in
     * UTC. A counter whose date is not today's UTC date reads 0 (nothing resets it at midnight; a new day's first event starts it at
     * 1), and so does a missing file. observed_at is the time of the counter's last update, or the report time when it reads 0.
     * Returns null, so the metric is left out, when the status folder is not configured or the file cannot be read.
     *
     * @param  string|null  $inside  the counter's name inside a status file's "counts" object, or null when the file is the counter
     */
    private function dailyCounterMetric(string $key, string $label, string $file, ?string $inside): ?array
    {
        try {
            $dir = rtrim($this->statusDir ?? (string) config('services.status_health.status_dir'), '/');
            if ($dir === '' || ! is_dir($dir)) {
                return null;
            }
            $counter = null;
            if (is_file("{$dir}/{$file}")) {
                $raw = @file_get_contents("{$dir}/{$file}");
                $data = $raw === false ? null : json_decode($raw, true);
                if (! is_array($data)) {
                    return null;
                }
                $counter = $inside === null ? $data : ($data['counts'][$inside] ?? null);
                if ($counter !== null && ! is_array($counter)) {
                    return null;
                }
            }

            $now = $this->now->utc();
            $today = is_array($counter) && ($counter['date'] ?? null) === $now->format('Y-m-d');
            $observed = $now;
            if ($today && isset($counter['updated_at'])) {
                try {
                    $observed = CarbonImmutable::parse((string) $counter['updated_at'], 'UTC')->utc();
                } catch (Throwable $e) {
                }
            }

            return [
                'key' => $key,
                'label' => $label,
                'value' => $today ? max(0, (int) ($counter['count'] ?? 0)) : 0,
                'unit' => 'count',
                'observed_at' => $observed->format('Y-m-d\TH:i:s\Z'),
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Ingestion

    /** Newest successful fetch among the active ICS sources (the scheduled collector, every 30 minutes). */
    public function ingestIcsAge(): array
    {
        $base = ['key' => 'ingest_ics_age', 'label' => 'Calendar feed fetches'];
        $sources = Source::query()->where('collector_type', 'ics')->where('active', true);
        if (! (clone $sources)->exists()) {
            return $base + ['status' => 'inactive', 'message' => 'No active ICS sources'];
        }
        $latest = $sources->max('last_success_at');
        if ($latest === null) {
            return $base + ['status' => 'critical', 'message' => 'No ICS source has ever been fetched successfully'];
        }
        $hours = $this->hoursSince(self::appTime((string) $latest));
        $status = $hours > 3 ? 'critical' : ($hours > 1.5 ? 'warning' : 'healthy');

        return $base + [
            'status' => $status,
            'value' => round($hours, 1),
            'unit' => 'hours',
            'message' => $status === 'healthy' ? null : 'Last successful ICS fetch was '.$this->ago($hours).' ago',
        ];
    }

    /** Active ICS sources whose last fetch failed. */
    public function sourcesFailing(): array
    {
        $base = ['key' => 'sources_failing', 'label' => 'Calendar sources failing'];
        $failing = Source::query()->where('collector_type', 'ics')->where('active', true)->whereNotNull('last_error')->count();

        return $base + [
            'status' => $failing > 0 ? 'warning' : 'healthy',
            'value' => $failing,
            'unit' => 'count',
            'message' => $failing > 0 ? $failing.' active ICS '.($failing === 1 ? 'source failed' : 'sources failed').' on its last fetch' : null,
        ];
    }

    public function sourcesDeactivated(): int
    {
        return Source::query()->whereIn('collector_type', self::INGESTED_COLLECTORS)->where('active', false)->count();
    }

    /** Hours since the newest successful fetch for a collector that is run by hand; null if there is none. */
    public function collectorAge(string $collectorType): ?float
    {
        $latest = Source::query()->where('collector_type', $collectorType)->max('last_success_at');

        return $latest === null ? null : round($this->hoursSince(self::appTime((string) $latest)), 1);
    }

    // ---------------------------------------------------------------------------------------------
    // Community

    /** Staging must stay invite-only. Not applicable anywhere else. */
    public function inviteGate(string $environment): array
    {
        $base = ['key' => 'exposure_invite_gate', 'label' => 'Invite-only gate'];
        if ($environment !== 'staging') {
            return $base + ['status' => 'inactive', 'message' => "Not expected on {$environment}"];
        }
        if (config('app.invite_only')) {
            return $base + ['status' => 'healthy'];
        }

        return $base + ['status' => 'critical', 'message' => 'The invite-only gate is off on staging, so every page is public'];
    }

    public function upcomingPublishedCount(): int
    {
        return Event::query()->published()->upcoming()->count();
    }

    public function eventsUpcomingPublished(): array
    {
        $base = ['key' => 'events_upcoming_published', 'label' => 'Upcoming published events'];
        $count = $this->upcomingPublishedCount();

        return $base + [
            'status' => $count > 0 ? 'healthy' : 'warning',
            'value' => $count,
            'unit' => 'count',
            'message' => $count > 0 ? null : 'No upcoming events are published',
        ];
    }

    public function moderationBacklog(): int
    {
        return Event::query()->whereIn('editorial_status', ['unreviewed', 'pending_review'])->count();
    }

    /** Days since the oldest event still waiting for review was added; null when none is waiting. */
    public function moderationOldestAgeDays(): ?float
    {
        $oldest = Event::query()->whereIn('editorial_status', ['unreviewed', 'pending_review'])->min('created_at');

        return $oldest === null ? null : round($this->hoursSince(self::appTime((string) $oldest)) / 24, 1);
    }

    public function submissionsWeek(): int
    {
        return PublicSubmission::query()->where('created_at', '>=', $this->now->subDays(7))->count();
    }

    /** Share of this week's submissions marked spam; null when there were none. */
    public function spamRateWeek(): ?float
    {
        $total = $this->submissionsWeek();
        if ($total === 0) {
            return null;
        }
        $spam = PublicSubmission::query()->where('created_at', '>=', $this->now->subDays(7))->where('status', 'spam')->count();

        return round(100 * $spam / $total, 1);
    }

    public function channelsActive(): int
    {
        return Channel::query()->where('status', 'active')->count();
    }

    public function curatorsActive(): int
    {
        return CuratorAssignment::query()->where('status', 'active')->distinct()->count('user_id');
    }

    // ---------------------------------------------------------------------------------------------
    // Mail and data endpoints

    /**
     * Mailer exceptions logged in the last 24 hours, counted from the end of the newest log file. A send that fails at handoff
     * leaves no database row (a failed invitation rolls back), so the log is the only record. Counts only; no log text leaves here.
     */
    public function mailFailures(): array
    {
        $base = ['key' => 'mail_failures', 'label' => 'Mail send failures'];
        $tail = $this->logTail();
        if ($tail === null) {
            return $base + ['status' => 'healthy', 'value' => 0, 'unit' => 'count'];
        }
        $zone = new DateTimeZone((string) config('app.timezone', 'UTC'));
        $count = 0;
        foreach (preg_split('/^(?=\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/m', $tail, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            if (! preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY):/', $entry, $m)) {
                continue;
            }
            if (! preg_match('~Mailer\\\\{1,4}Exception~', $entry)) {
                continue;
            }
            $time = CarbonImmutable::createFromFormat('Y-m-d H:i:s', str_replace('T', ' ', $m[1]), $zone);
            if ($time !== false && $this->now->getTimestamp() - $time->getTimestamp() <= 86400) {
                $count++;
            }
        }

        return $base + [
            'status' => $count > 0 ? 'warning' : 'healthy',
            'value' => $count,
            'unit' => 'count',
            'message' => $count > 0 ? $count.' mail '.($count === 1 ? 'send' : 'sends').' failed in the last 24 hours (at least)' : null,
        ];
    }

    /** The data endpoints compress their own JSON (the web server does not). Checks the means are in place, not the wire. */
    public function dataCompression(): array
    {
        $base = ['key' => 'data_compression_enabled', 'label' => 'Data endpoint compression'];
        $uses = fn (string $class) => in_array(CompressesJson::class, class_uses($class) ?: [], true);
        if (! function_exists('deflate_init')) {
            return $base + ['status' => 'critical', 'message' => 'The zlib stream functions are not available'];
        }
        if (! $uses(QueryController::class) || ! $uses(SummaryController::class)) {
            return $base + ['status' => 'critical', 'message' => 'A data endpoint no longer compresses its answer'];
        }

        return $base + ['status' => 'healthy'];
    }

    // ---------------------------------------------------------------------------------------------

    private function logTail(): ?string
    {
        $files = glob(rtrim($this->logDir ?? storage_path('logs'), '/').'/*.log') ?: [];
        if ($files === []) {
            return null;
        }
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $size = (int) filesize($files[0]);
        $handle = @fopen($files[0], 'rb');
        if ($handle === false) {
            return null;
        }
        $read = min($size, 2 * 1024 * 1024);
        fseek($handle, $size - $read);
        $tail = (string) fread($handle, $read);
        fclose($handle);

        return $tail;
    }

    private function ago(float $hours): string
    {
        if ($hours < 2) {
            return round($hours * 60).' minutes';
        }
        if ($hours < 48) {
            return round($hours).' hours';
        }

        return round($hours / 24).' days';
    }
}
