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

    /** Collectors that ingest.* commands run; ICS feeds and the feeds marked "scheduled" are collected by the scheduler. */
    private const INGESTED_COLLECTORS = ['ics', 'tribe_events', 'squarespace_events', 'growthzone'];

    public function __construct(
        private readonly CarbonImmutable $now,
        private readonly ?string $logDir = null,
        private readonly ?string $statusDir = null,
        private readonly ?string $basePath = null,
        private readonly ?string $sessionsDir = null,
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
            $result = $check();
        } catch (Throwable $e) {
            $result = ['key' => $key, 'label' => $label, 'status' => 'unknown', 'message' => 'Check failed to run'];
        }

        return self::withMeaning($result);
    }

    /**
     * What a visitor sees and what to do, per own check (contract revision 10, section 5.2). Each entry is the impact at warning,
     * the impact at critical, and one sentence of action (at most 160 characters: what to do and who does it). The dashboard shows
     * these only next to a check that is not healthy, so they are added only for warning, critical and unknown.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const MEANING = [
        'weather_freshness_live_conditions' => ['risk', 'visible', 'Check live_update.php\'s last run and the Open-Meteo quota; Michael runs any server step.'],
        'weather_live_cities_stale' => ['visible', 'visible', 'Check the live-conditions source for the cities named (MRV\'s team).'],
        'weather_freshness_daily_weather' => ['risk', 'visible', 'Check daily_update.php\'s last run and quota_stops_today, then rerun it over the missing days (Michael, on the server).'],
        'weather_daily_coverage' => ['risk', 'visible', 'Rerun daily_update.php over the dates named (Michael, on the server); if gaps recur, change its city order (Claude).'],
        'weather_freshness_air_quality' => ['risk', 'visible', 'Check air_quality_update.php\'s last run and the Open-Meteo quota (Michael); the job refills recent days.'],
        'weather_freshness_climate_snapshot' => ['monitoring', 'risk', 'Check climate_snapshot.php\'s last run and the Open-Meteo quota (Michael); it continues the open snapshot every hour.'],
        'weather_backfill_progress' => ['monitoring', 'monitoring', 'Check backfill_worker.php\'s last run and the Open-Meteo quota (Michael); the backfill resumes by itself when the allowance allows.'],
        'weather_backfill_integrity' => ['monitoring', 'risk', 'Rerun the backfill for the chunks named, or load the missing days with daily_update.php (Claude plans it; Michael runs it).'],
        'backup_age' => ['risk', 'risk', 'Free space at the backup destination or check the JetBackup schedule in the hosting panel (Michael); backups resume on their own.'],
        'weather_completeness' => ['risk', 'visible', 'Read live_update.php\'s output for the cities with no temperature or no report (MRV\'s team).'],
        'ingest_ics_age' => ['risk', 'visible', 'Check the ingest:ics run and the dispatcher on the server (Michael); calendar feeds are fetched every 30 minutes.'],
        'sources_failing' => ['risk', 'risk', 'Read the last error of each failing source in the admin and fix it or switch it off (Michael).'],
        'events_upcoming_published' => ['risk', 'risk', 'Approve candidate events in the moderation queue (Michael or a curator).'],
        'mail_failures' => ['visible', 'visible', 'Read the Mailer exception in laravel.log; check the Postmark server and token (Michael).'],
        'data_compression_enabled' => ['risk', 'risk', 'Restore the zlib extension or the CompressesJson trait on the data endpoints (Claude; Michael for the PHP extension).'],
    ];

    /** Adds `impact` and `action` to a check result that is not healthy or inactive, when the check has an entry in MEANING. */
    public static function withMeaning(array $result): array
    {
        $status = $result['status'] ?? null;
        $entry = self::MEANING[$result['key'] ?? ''] ?? null;
        if ($entry === null || ! in_array($status, ['warning', 'critical', 'unknown'], true)) {
            return $result;
        }
        if ($status === 'unknown') {
            return $result + ['impact' => 'monitoring', 'action' => 'The check failed to run; read storage/logs/laravel.log and fix the check (Claude).'];
        }

        return $result + ['impact' => $status === 'critical' ? $entry[1] : $entry[0], 'action' => $entry[2]];
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

    /** The window of Pacific days the coverage check looks at: from 30 days back to 3 days back (the archive lags about two days). */
    public const COVERAGE_FIRST_DAYS_BACK = 30;

    public const COVERAGE_LAST_DAYS_BACK = 3;

    /** The daily job re-fetches a window of 5 days; a missing day older than this many days is not refilled by it. */
    public const DAILY_JOB_REFILL_DAYS = 4;

    /**
     * Cities (named and grid) with no weather_daily row for a day that should be complete. The freshness check above looks only at
     * the 36 named cities' newest date, so a grid city that missed days (the daily job stops for the whole list when the quota is
     * spent) went unnoticed. Warning for any missing city-day; critical when a missing day is too old for the daily job to refill.
     */
    public function dailyCoverage(): array
    {
        $base = ['key' => 'weather_daily_coverage', 'label' => 'Daily weather coverage'];
        $today = $this->pacificNow();
        $first = $today->subDays(self::COVERAGE_FIRST_DAYS_BACK)->format('Y-m-d');
        $last = $today->subDays(self::COVERAGE_LAST_DAYS_BACK)->format('Y-m-d');
        $oldestRefillable = $today->subDays(self::DAILY_JOB_REFILL_DAYS)->format('Y-m-d');

        $cityIds = $this->weather()->table('cities')->whereIn('place_type', ['city', 'grid'])->pluck('id');
        if ($cityIds->isEmpty()) {
            return $base + ['status' => 'critical', 'message' => 'No cities are stored'];
        }

        $perDay = $this->weather()->table('weather_daily')
            ->whereIn('city_id', $cityIds)
            ->whereBetween('date', [$first, $last])
            ->selectRaw('date, COUNT(*) as n')
            ->groupBy('date')
            ->pluck('n', 'date');

        $missingByDay = [];
        for ($day = CarbonImmutable::parse($first); $day->format('Y-m-d') <= $last; $day = $day->addDay()) {
            $missing = $cityIds->count() - (int) ($perDay[$day->format('Y-m-d')] ?? 0);
            if ($missing > 0) {
                $missingByDay[$day->format('Y-m-d')] = $missing;
            }
        }
        $total = array_sum($missingByDay);
        if ($total === 0) {
            return $base + ['status' => 'healthy', 'value' => 0, 'unit' => 'count'];
        }

        $tooOld = array_filter($missingByDay, fn ($n, $date) => $date < $oldestRefillable, ARRAY_FILTER_USE_BOTH);
        $listed = [];
        foreach (array_slice($missingByDay, 0, 6, true) as $date => $n) {
            $listed[] = "{$date} ({$n})";
        }

        return $base + [
            'status' => $tooOld === [] ? 'warning' : 'critical',
            'value' => $total,
            'unit' => 'count',
            'message' => "{$total} city-days have no daily weather row: ".implode(', ', $listed).(count($missingByDay) > 6 ? ', ...' : '')
                .($tooOld === [] ? '' : '; the daily job no longer refills days before '.$oldestRefillable),
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Backfill (api/cron/backfill_worker.php): the 25 newer named cities and 441 grid points, one chunk per location and year

    /** The cities the backfill skips. Same list as ORIGINAL_11 in api/cron/backfill_worker.php; a test compares the two. */
    public const BACKFILL_ORIGINAL_CITIES = [
        'Victorville', 'Hesperia', 'Apple Valley', 'Adelanto', 'Barstow',
        'Phelan', 'Big Bear City', 'Joshua Tree', 'Mojave', 'Lucerne Valley', 'Oak Hills',
    ];

    /** First year of a backfill (START_YEAR in the worker). */
    public const BACKFILL_START_YEAR = 2016;

    /**
     * backfill_progress.completed_at is MySQL NOW() on the host, which runs on Eastern time (checked 2026-11-22: NOW() read
     * 19:12 EST while UTC was 00:12).
     */
    public const BACKFILL_CLOCK = 'America/New_York';

    /**
     * Hours since the last completed chunk before the progress check warns, and before it is critical. Set from a replay of the 1,384
     * chunks of 2026-07-30 to 2026-11-21 (scripts/server-checks/output/2026-11-22 backfill gap replay production.txt): since
     * 2026-09-17 the longest gap between two chunks was 48 hours; the only longer ones (68 and 96 hours) were in August.
     */
    public const BACKFILL_WARN_HOURS = 60;

    public const BACKFILL_CRITICAL_HOURS = 120;

    /** Share of completed chunks that may have lost rows before the integrity check is critical. */
    public const BACKFILL_INTEGRITY_CRITICAL_SHARE = 0.01;

    /** @var array<string, mixed>|null */
    private ?array $backfillState = null;

    /**
     * The worker's queue as numbers: the target locations, the chunks expected (targets times years), the chunks done, the newest
     * completion and the completions in the last 7 days.
     *
     * @return array<string, mixed>
     */
    private function backfillState(): array
    {
        if ($this->backfillState !== null) {
            return $this->backfillState;
        }
        $clock = new DateTimeZone(self::BACKFILL_CLOCK);
        $targets = $this->weather()->table('cities')
            ->whereIn('place_type', ['city', 'grid'])
            ->whereNotIn('name', self::BACKFILL_ORIGINAL_CITIES)
            ->pluck('id')->all();
        $year = (int) $this->now->setTimezone($clock)->format('Y');
        $years = max(0, $year - self::BACKFILL_START_YEAR + 1);
        $done = 0;
        $last = null;
        $recent = 0;
        if ($targets !== []) {
            $chunks = $this->weather()->table('backfill_progress')
                ->where('kind', 'backfill')
                ->whereIn('city_id', $targets)
                ->whereBetween('year', [self::BACKFILL_START_YEAR, $year]);
            $done = (clone $chunks)->select('city_id', 'year')->groupBy('city_id', 'year')->get()->count();
            $newest = (clone $chunks)->max('completed_at');
            $last = $newest !== null ? CarbonImmutable::createFromFormat('Y-m-d H:i:s', (string) $newest, $clock) : null;
            $since = $this->now->subDays(7)->setTimezone($clock)->format('Y-m-d H:i:s');
            $recent = (clone $chunks)->where('completed_at', '>=', $since)->count();
        }
        $expected = count($targets) * $years;

        return $this->backfillState = [
            'targets' => count($targets),
            'expected' => $expected,
            'done' => min($done, $expected),
            'remaining' => max(0, $expected - $done),
            'last' => $last,
            'rate_7d' => $recent / 7,
        ];
    }

    public function backfillChunksDone(): float
    {
        return (float) $this->backfillState()['done'];
    }

    public function backfillChunksRemaining(): float
    {
        return (float) $this->backfillState()['remaining'];
    }

    /** Chunks completed a day, as the average of the last 7 days. */
    public function backfillRate7d(): float
    {
        return round($this->backfillState()['rate_7d'], 1);
    }

    /** Days to finish at the 7-day rate; null when nothing was completed in the last 7 days or nothing is left. */
    public function backfillEtaDays(): ?float
    {
        $state = $this->backfillState();

        return $state['rate_7d'] > 0 && $state['remaining'] > 0 ? round($state['remaining'] / $state['rate_7d']) : null;
    }

    /**
     * Time since the backfill last completed a chunk. The worker runs hourly and a day's budget caps it near 31 chunks, so days
     * with none do happen (2026-11-12, 11-14 and 11-18 had none); the limits are above one such day. Inactive when no chunk is left.
     */
    public function backfillProgress(): array
    {
        $base = ['key' => 'weather_backfill_progress', 'label' => 'Backfill progress'];
        $state = $this->backfillState();
        if ($state['targets'] === 0) {
            return $base + ['status' => 'inactive', 'message' => 'No backfill targets'];
        }
        if ($state['remaining'] === 0) {
            return $base + ['status' => 'inactive', 'message' => 'Backfill complete'];
        }
        $detail = number_format($state['remaining']).' of '.number_format($state['expected']).' chunks remain, '.number_format($state['rate_7d'], 1).' a day over the last 7 days';
        if ($state['last'] === null) {
            return $base + ['status' => 'warning', 'message' => 'No backfill chunk has been completed; '.$detail];
        }
        $hours = $this->hoursSince($state['last']);
        $status = $hours > self::BACKFILL_CRITICAL_HOURS ? 'critical' : ($hours > self::BACKFILL_WARN_HOURS ? 'warning' : 'healthy');

        return $base + [
            'status' => $status,
            'value' => round($hours, 1),
            'unit' => 'hours',
            'message' => $status === 'healthy' ? null : 'The last chunk finished '.$this->ago($hours).' ago; '.$detail,
        ];
    }

    /**
     * Completed chunks whose year holds fewer stored daily rows than the chunk sent. More stored rows are normal: the daily job adds
     * the days after the chunk (2026 chunks sent 240 rows and now hold 324). Read 2026-11-22: 0 of 1,384 chunks had lost rows.
     */
    public function backfillIntegrity(): array
    {
        $base = ['key' => 'weather_backfill_integrity', 'label' => 'Backfill integrity'];
        $state = $this->backfillState();
        if ($state['targets'] === 0 || $state['done'] === 0) {
            return $base + ['status' => 'inactive', 'message' => 'No completed chunks'];
        }
        $weather = $this->weather();
        $year = $weather->getDriverName() === 'sqlite' ? "cast(strftime('%Y', date) as integer)" : 'year(date)';
        $stored = [];
        foreach ($weather->select("select city_id, {$year} as y, count(*) as c from weather_daily group by city_id, y") as $row) {
            $stored[$row->city_id.':'.$row->y] = (int) $row->c;
        }
        $total = 0;
        $short = [];
        foreach ($weather->table('backfill_progress')->where('kind', 'backfill')->get(['city_id', 'year', 'rows_sent']) as $chunk) {
            $total++;
            $have = $stored[$chunk->city_id.':'.$chunk->year] ?? 0;
            if ($have < (int) $chunk->rows_sent) {
                $short[] = "city {$chunk->city_id} {$chunk->year} ({$have} of {$chunk->rows_sent})";
            }
        }
        $count = count($short);
        $status = $count === 0 ? 'healthy' : ($count / max(1, $total) > self::BACKFILL_INTEGRITY_CRITICAL_SHARE ? 'critical' : 'warning');

        return $base + [
            'status' => $status,
            'value' => $count,
            'unit' => 'count',
            'message' => $count === 0 ? null : $count.' of '.$total.' completed chunks hold fewer rows than they sent: '.implode(', ', array_slice($short, 0, 3)).($count > 3 ? ', ...' : ''),
        ];
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

    /**
     * Equivalent Open-Meteo calls sent today (UTC day). The cron scripts add every request they send, whichever script sends it, to
     * <status folder>/counter_openmeteo.json (api/cron/openmeteo_limits.php, openmeteo_record_request()); that is the figure when the
     * file exists. Without it (before the first request was recorded, or no status folder) the figure falls back to the earlier
     * estimate: the backfill and air quality budgets plus 480 calls for each climate pair, which misses daily_update.
     */
    public function openMeteoCallsToday(): ?float
    {
        $counter = $this->openMeteoCounter();
        if ($counter !== null) {
            return $this->counterIsToday($counter) ? round(max(0.0, (float) ($counter['equivalent_calls'] ?? 0)), 1) : 0.0;
        }

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

    /** Requests sent to Open-Meteo today (UTC day), from the same counter. Null when the counter file does not exist. */
    public function openMeteoRequestsToday(): ?int
    {
        $counter = $this->openMeteoCounter();

        return $counter === null ? null : ($this->counterIsToday($counter) ? max(0, (int) ($counter['requests'] ?? 0)) : 0);
    }

    /** Requests Open-Meteo refused today for a limit (daily, hourly or per-minute), from the same counter. Null when there is no counter. */
    public function openMeteoRefusalsToday(): ?int
    {
        $counter = $this->openMeteoCounter();
        if ($counter === null) {
            return null;
        }
        if (! $this->counterIsToday($counter)) {
            return 0;
        }
        $outcomes = is_array($counter['outcomes'] ?? null) ? $counter['outcomes'] : [];

        return max(0, (int) ($outcomes['daily'] ?? 0)) + max(0, (int) ($outcomes['hourly'] ?? 0)) + max(0, (int) ($outcomes['minutely'] ?? 0));
    }

    /**
     * The observed ceiling: equivalent calls this project had sent before the most recent daily refusal, on whatever day it
     * happened (the counter carries it across days). It measures where the wall was, not the allowance left. Null when no daily
     * refusal has been recorded.
     */
    public function openMeteoObservedCeiling(): ?float
    {
        $last = $this->openMeteoCounter()['last_daily_refusal'] ?? null;

        return is_array($last) && isset($last['equivalent_calls_before']) ? round((float) $last['equivalent_calls_before'], 1) : null;
    }

    /** The request counter as stored, or null when the status folder is not configured or the file is missing or unreadable. */
    private function openMeteoCounter(): ?array
    {
        try {
            $dir = rtrim($this->statusDir ?? (string) config('services.status_health.status_dir'), '/');
            if ($dir === '' || ! is_file("{$dir}/counter_openmeteo.json")) {
                return null;
            }
            $raw = @file_get_contents("{$dir}/counter_openmeteo.json");
            $data = $raw === false ? null : json_decode($raw, true);

            return is_array($data) ? $data : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Whether the counter's UTC date is today's; a counter from an earlier day reads as 0 (nothing resets it at midnight). */
    private function counterIsToday(array $counter): bool
    {
        return ($counter['date'] ?? null) === $this->now->utc()->format('Y-m-d');
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

    /** Scheduled sources (see Source::scheduled()) whose last fetch failed. */
    public function sourcesFailing(): array
    {
        $base = ['key' => 'sources_failing', 'label' => 'Calendar sources failing'];
        $failing = Source::scheduled()->whereNotNull('last_error')->count();

        return $base + [
            'status' => $failing > 0 ? 'warning' : 'healthy',
            'value' => $failing,
            'unit' => 'count',
            'message' => $failing > 0 ? $failing.' collected '.($failing === 1 ? 'source failed' : 'sources failed').' on its last fetch' : null,
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
    // Backups

    /** Hours after which the newest backup makes the check warn, and critical. JetBackup runs daily; 26 allows a late run. */
    public const BACKUP_WARN_HOURS = 26;

    public const BACKUP_CRITICAL_HOURS = 50;

    /** Hours after which the daily reading itself is treated as missed (two runs). */
    public const BACKUP_READING_STALE_HOURS = 49;

    /**
     * Age of the account's newest JetBackup5 backup, from <status folder>/backup_age.json, which api/cron/backup_age_update.php writes
     * once a day (it lists backups through the cPanel UAPI; read-only). The age is taken from all_types_newest_at, the oldest of the
     * per-type newest times, so one backup type that stopped while others continue is seen. Inactive until the first reading exists.
     */
    public function backupAge(): array
    {
        $base = ['key' => 'backup_age', 'label' => 'Account backups'];
        $dir = rtrim($this->statusDir ?? (string) config('services.status_health.status_dir'), '/');
        if ($dir === '' || ! is_file("{$dir}/backup_age.json")) {
            return $base + ['status' => 'inactive', 'message' => 'No backup reading has been made yet'];
        }
        $raw = @file_get_contents("{$dir}/backup_age.json");
        $data = $raw === false ? null : json_decode($raw, true);
        if (! is_array($data)) {
            return $base + ['status' => 'unknown', 'message' => 'The backup reading file cannot be read'];
        }
        $error = is_string($data['error'] ?? null) && $data['error'] !== '' ? $data['error'] : null;

        $lastOk = $this->backupTime($data['last_ok_at'] ?? null);
        if ($lastOk === null) {
            return $base + ['status' => 'warning', 'message' => 'The backup list has not been read successfully'.($error !== null ? ': '.$error : '')];
        }
        $readingHours = $this->hoursSince($lastOk);
        $newest = $this->backupTime($data['all_types_newest_at'] ?? null);
        if ($newest === null) {
            return $base + ['status' => 'critical', 'message' => 'No backup was found in the list'];
        }

        $hours = $this->hoursSince($newest);
        $status = $hours > self::BACKUP_CRITICAL_HOURS ? 'critical' : ($hours > self::BACKUP_WARN_HOURS ? 'warning' : 'healthy');
        $parts = [];
        if ($status !== 'healthy') {
            $parts[] = 'The newest backup is '.$this->ago($hours).' old ('.$newest->format('Y-m-d H:i').' UTC)';
        }
        if ($readingHours > self::BACKUP_READING_STALE_HOURS) {
            $status = $status === 'critical' ? 'critical' : 'warning';
            $parts[] = 'the backup list was last read '.$this->ago($readingHours).' ago';
        }
        if ($error !== null && $readingHours > self::BACKUP_WARN_HOURS) {
            $parts[] = $error;
        }

        return $base + [
            'status' => $status,
            'value' => round($hours, 1),
            'unit' => 'hours',
            'message' => $parts === [] ? null : ucfirst(implode('; ', $parts)),
        ];
    }

    private function backupTime(mixed $value): ?CarbonImmutable
    {
        try {
            return is_string($value) && $value !== '' ? CarbonImmutable::parse($value, 'UTC')->utc() : null;
        } catch (Throwable $e) {
            return null;
        }
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
    // Footprint: how much this copy and its data occupy (metrics with no thresholds; the account's own inode and disk totals are
    // tracked at the dashboard, not here)

    /** Files and links under this copy's folder (vendor included), the figure the account's inode limit is spent on. */
    public function ownFileCount(): ?int
    {
        return $this->countFiles($this->basePath ?? base_path());
    }

    /** Files in the sessions folder, the likeliest place for the count to grow unnoticed. */
    public function sessionsFileCount(): ?int
    {
        return $this->countFiles($this->sessionsDir ?? storage_path('framework/sessions'));
    }

    /** Files in the status folder (/home/nortonah/mrv-status), one per cron job plus the daily counters. */
    public function statusFolderFileCount(): ?int
    {
        $dir = rtrim($this->statusDir ?? (string) config('services.status_health.status_dir'), '/');

        return $dir === '' ? null : $this->countFiles($dir);
    }

    /** Megabytes the log files in storage/logs occupy. */
    public function storageLogsMb(): ?float
    {
        $dir = rtrim($this->logDir ?? storage_path('logs'), '/');
        if (! is_dir($dir)) {
            return null;
        }
        $bytes = 0;
        foreach (glob($dir.'/*') ?: [] as $file) {
            if (is_file($file)) {
                $bytes += (int) filesize($file);
            }
        }

        return round($bytes / 1048576, 1);
    }

    /** Megabytes (data and indexes) the weather database occupies; null where the database is not MySQL. */
    public function weatherDbSizeMb(): ?float
    {
        return $this->databaseSizeMb($this->weather());
    }

    /** Megabytes (data and indexes) this copy's own application database occupies; null where the database is not MySQL. */
    public function appDbSizeMb(): ?float
    {
        return $this->databaseSizeMb(DB::connection());
    }

    /** Rows in weather_daily, the table the backfill grows. */
    public function weatherDailyRows(): ?int
    {
        return (int) $this->weather()->table('weather_daily')->count();
    }

    /**
     * Scheduled commands that failed in the last 24 hours, counted from the end of the newest log file: Laravel logs
     * 'The command "..." failed.' when a scheduled background command exits non-zero or its process cannot start (a fork failure on
     * the shared host leaves one of these). Counts only; no log text leaves here.
     */
    public function schedulerCommandFailures24h(): ?int
    {
        $tail = $this->logTail();
        if ($tail === null) {
            return 0;
        }
        $zone = new DateTimeZone((string) config('app.timezone', 'UTC'));
        $count = 0;
        foreach (preg_split('/^(?=\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/m', $tail, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            if (! preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY): The command /', $entry, $m)) {
                continue;
            }
            $time = CarbonImmutable::createFromFormat('Y-m-d H:i:s', str_replace('T', ' ', $m[1]), $zone);
            if ($time !== false && $this->now->getTimestamp() - $time->getTimestamp() <= 86400) {
                $count++;
            }
        }

        return $count;
    }

    /** Every entry below a folder that is not a folder: files, links and special files. Null when the folder does not exist. */
    private function countFiles(string $dir): ?int
    {
        if (! is_dir($dir)) {
            return null;
        }
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );
        foreach ($iterator as $unused) {
            $count++;
        }

        return $count;
    }

    private function databaseSizeMb(Connection $connection): ?float
    {
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return null;
        }
        $bytes = $connection->selectOne(
            'select sum(data_length + index_length) as bytes from information_schema.tables where table_schema = database()'
        )->bytes ?? null;

        return $bytes === null ? null : round((float) $bytes / 1048576, 1);
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
