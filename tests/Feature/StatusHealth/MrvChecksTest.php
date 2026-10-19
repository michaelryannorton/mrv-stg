<?php

namespace Tests\Feature\StatusHealth;

use App\Models\Channel;
use App\Models\CuratorAssignment;
use App\Models\Event;
use App\Models\PublicSubmission;
use App\Models\Source;
use App\Models\User;
use App\Support\StatusHealth\MrvChecks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Mojave River Valley's own health checks (App\Support\StatusHealth\MrvChecks) with a fixed clock: thresholds, the zone each
 * source's times are converted from, and the cases where a check stays quiet (inactive).
 *
 * The clock is 2026-10-18 20:00 UTC, which is 13:00 Pacific Daylight Time.
 */
class MrvChecksTest extends StatusHealthTestCase
{
    private function mrv(?string $logDir = null): MrvChecks
    {
        return new MrvChecks(CarbonImmutable::parse('2026-10-18 20:00:00', 'UTC'), $logDir);
    }

    private function city(int $id, string $name = 'Victorville', string $type = 'city'): void
    {
        DB::connection('weather')->table('cities')->insert(['id' => $id, 'name' => $name, 'place_type' => $type, 'latitude' => 34.5, 'longitude' => -117.3]);
    }

    private function live(int $city, string $pacificTime, ?float $temp = 70.0): void
    {
        DB::connection('weather')->table('live_conditions_log')->insert(['city_id' => $city, 'observed_at' => $pacificTime, 'temp_f' => $temp]);
    }

    // --- time -----------------------------------------------------------------------------------------------------------

    public function test_pacific_times_are_converted_to_utc_with_daylight_saving(): void
    {
        $this->assertSame('2026-10-18T19:30:00Z', MrvChecks::pacificToUtc('2026-10-18 12:30:00')->format('Y-m-d\TH:i:s\Z'), 'PDT is UTC-7');
        $this->assertSame('2026-12-01T20:30:00Z', MrvChecks::pacificToUtc('2026-12-01 12:30:00')->format('Y-m-d\TH:i:s\Z'), 'PST is UTC-8');
    }

    public function test_the_hour_that_repeats_when_the_clocks_go_back_is_read_as_the_later_one(): void
    {
        // 2026-11-01: at 02:00 PDT the clocks go back to 01:00 PST, so 01:00 to 01:59 happens twice.
        $this->assertSame('2026-11-01T07:59:59Z', MrvChecks::pacificToUtc('2026-11-01 00:59:59')->format('Y-m-d\TH:i:s\Z'), 'before the repeated hour: PDT');
        $this->assertSame('2026-11-01T09:00:00Z', MrvChecks::pacificToUtc('2026-11-01 01:00:00')->format('Y-m-d\TH:i:s\Z'), 'repeated hour: the later (PST) reading');
        $this->assertSame('2026-11-01T09:30:00Z', MrvChecks::pacificToUtc('2026-11-01 01:30:00')->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame('2026-11-01T10:00:00Z', MrvChecks::pacificToUtc('2026-11-01 02:00:00')->format('Y-m-d\TH:i:s\Z'), 'after: PST');
    }

    public function test_the_fall_back_night_cannot_make_live_data_look_older_than_it_is(): void
    {
        $this->city(1);
        // A reading stamped 01:30 on the repeated hour. At 09:45 UTC (01:45 PST) it is 15 minutes old at most, never 75.
        $this->live(1, '2026-11-01 01:30:00');
        $clock = new MrvChecks(CarbonImmutable::parse('2026-11-01 09:45:00', 'UTC'));

        $this->assertSame('healthy', $clock->liveConditions()['status']);
    }

    public function test_calendar_day_differences(): void
    {
        $this->assertSame(0, MrvChecks::daysBetween('2026-10-18', '2026-10-18'));
        $this->assertSame(2, MrvChecks::daysBetween('2026-10-16', '2026-10-18'));
        $this->assertSame(1, MrvChecks::daysBetween('2026-10-31', '2026-11-01'));
        $this->assertSame(3, MrvChecks::daysBetween('2026-10-30', '2026-11-02'), 'across the clock change');
    }

    // --- live conditions ------------------------------------------------------------------------------------------------

    public function test_live_conditions_thresholds(): void
    {
        $this->city(1);
        $cases = [
            '2026-10-18 12:30:00' => ['healthy', 0.5],   // 19:30 UTC, 30 minutes old
            '2026-10-18 11:50:00' => ['warning', 1.2],   // 70 minutes
            '2026-10-18 10:50:00' => ['critical', 2.2],  // 130 minutes
        ];
        foreach ($cases as $observed => [$status, $hours]) {
            DB::connection('weather')->table('live_conditions_log')->delete();
            $this->live(1, $observed);
            $check = $this->mrv()->liveConditions();
            $this->assertSame($status, $check['status'], $observed);
            $this->assertSame($hours, $check['value'], $observed);
            $this->assertSame('hours', $check['unit']);
        }
    }

    public function test_live_conditions_uses_the_newest_row_of_any_city(): void
    {
        $this->city(1);
        $this->city(7, 'Big Bear City');
        $this->live(1, '2026-10-18 06:00:00');
        $this->live(7, '2026-10-18 12:40:00');

        $this->assertSame('healthy', $this->mrv()->liveConditions()['status']);
    }

    public function test_an_empty_live_table_is_critical(): void
    {
        $check = $this->mrv()->liveConditions();

        $this->assertSame('critical', $check['status']);
        $this->assertSame('No live observations are stored', $check['message']);
    }

    public function test_a_city_that_stopped_reporting_is_counted_stale(): void
    {
        $this->city(1);
        $this->city(7, 'Big Bear City');
        $this->live(1, '2026-10-18 12:30:00');
        $this->live(7, '2026-10-18 07:00:00');   // six hours ago

        $check = $this->mrv()->liveCitiesStale();

        $this->assertSame('critical', $check['status'], 'One of two is half of the cities.');
        $this->assertSame(1, $check['value']);
        $this->assertSame('1 of 2 cities have not reported in 3 hours', $check['message']);

        $this->city(8, 'Mojave');
        $this->live(8, '2026-10-18 12:00:00');
        $this->assertSame('warning', $this->mrv()->liveCitiesStale()['status'], 'One of three.');
    }

    public function test_stale_cities_is_quiet_when_nothing_has_reported_for_a_week(): void
    {
        $this->city(1);
        $this->live(1, '2026-10-01 12:00:00');

        $this->assertSame('inactive', $this->mrv()->liveCitiesStale()['status']);
    }

    // --- daily tables ---------------------------------------------------------------------------------------------------

    public function test_daily_weather_is_measured_in_days_behind_the_pacific_date(): void
    {
        $this->city(1);
        $this->city(500, 'Grid point', 'grid');
        $db = DB::connection('weather')->table('weather_daily');
        // Today in Pacific is 2026-10-18. The archive normally lags about two days.
        foreach ([['2026-10-16', 'healthy', 2], ['2026-10-15', 'healthy', 3], ['2026-10-14', 'warning', 4], ['2026-10-13', 'warning', 5], ['2026-10-12', 'critical', 6]] as [$newest, $status, $days]) {
            $db->delete();
            $db->insert(['city_id' => 1, 'date' => $newest]);
            $db->insert(['city_id' => 500, 'date' => '2020-01-01']);   // a grid point, long idle, must not count
            $check = $this->mrv()->dailyWeather();
            $this->assertSame($status, $check['status'], $newest);
            $this->assertSame($days, $check['value'], $newest);
            $this->assertSame('days', $check['unit']);
        }
    }

    public function test_daily_weather_reports_the_laggard_among_the_named_cities(): void
    {
        $this->city(1);
        $this->city(7, 'Big Bear City');
        $db = DB::connection('weather')->table('weather_daily');
        $db->insert(['city_id' => 1, 'date' => '2026-10-17']);
        $db->insert(['city_id' => 7, 'date' => '2026-10-10']);

        $check = $this->mrv()->dailyWeather();

        $this->assertSame('critical', $check['status']);
        $this->assertSame('1 of 2 cities are more than 3 days behind; the oldest is 8 days behind', $check['message']);
    }

    public function test_air_quality_is_one_day_fresher_than_weather(): void
    {
        $this->city(1);
        $db = DB::connection('weather')->table('air_quality_daily');
        foreach ([['2026-10-17', 'healthy', 1], ['2026-10-16', 'healthy', 2], ['2026-10-15', 'warning', 3], ['2026-10-14', 'warning', 4], ['2026-10-13', 'critical', 5]] as [$newest, $status, $days]) {
            $db->delete();
            $db->insert(['city_id' => 1, 'date' => $newest]);
            $check = $this->mrv()->airQuality();
            $this->assertSame($status, $check['status'], $newest);
            $this->assertSame($days, $check['value'], $newest);
        }
    }

    public function test_a_daily_table_with_no_named_city_rows_is_critical(): void
    {
        $this->assertSame('critical', $this->mrv()->dailyWeather()['status']);
        $this->assertSame('critical', $this->mrv()->airQuality()['status']);
    }

    // --- climate snapshot -----------------------------------------------------------------------------------------------

    public function test_climate_is_inactive_without_an_open_snapshot(): void
    {
        $this->assertSame('inactive', $this->mrv()->climateSnapshot()['status']);
        $this->assertSame('No snapshot open', $this->mrv()->climateSnapshot()['message']);

        // A snapshot with every pair done is not open.
        DB::connection('weather')->table('climate_snapshots')->insert(['snapshot' => '2026-09-01', 'pairs_expected' => 2]);
        DB::connection('weather')->table('climate_fetch_progress')->insert([
            ['snapshot' => '2026-09-01', 'city_id' => 1, 'model' => 'a', 'completed_at' => '2026-09-02 10:00:00'],
            ['snapshot' => '2026-09-01', 'city_id' => 1, 'model' => 'b', 'completed_at' => '2026-09-02 10:00:00'],
        ]);
        $this->assertSame('inactive', $this->mrv()->climateSnapshot()['status']);
    }

    public function test_an_open_snapshot_is_measured_from_its_newest_completed_pair_in_utc(): void
    {
        DB::connection('weather')->table('climate_snapshots')->insert(['snapshot' => '2026-10-12', 'pairs_expected' => 77]);
        $progress = DB::connection('weather')->table('climate_fetch_progress');
        // completed_at is a UTC string written by the job. 20:00 UTC now.
        foreach ([['2026-10-18 10:00:00', 'healthy', 10.0], ['2026-10-17 00:00:00', 'warning', 44.0], ['2026-10-15 10:00:00', 'critical', 82.0]] as [$completed, $status, $hours]) {
            $progress->delete();
            $progress->insert(['snapshot' => '2026-10-12', 'city_id' => 1, 'model' => 'a', 'completed_at' => $completed]);
            $check = $this->mrv()->climateSnapshot();
            $this->assertSame($status, $check['status'], $completed);
            $this->assertSame($hours, $check['value'], $completed);
        }
    }

    public function test_only_the_newest_open_snapshot_counts(): void
    {
        DB::connection('weather')->table('climate_snapshots')->insert([['snapshot' => '2026-10-12', 'pairs_expected' => 2], ['snapshot' => '2026-10-01', 'pairs_expected' => 2]]);
        DB::connection('weather')->table('climate_fetch_progress')->insert([
            ['snapshot' => '2026-10-12', 'city_id' => 1, 'model' => 'a', 'completed_at' => '2026-10-18 19:00:00'],
            ['snapshot' => '2026-10-01', 'city_id' => 1, 'model' => 'a', 'completed_at' => '2026-10-02 00:00:00'],
        ]);

        $this->assertSame('healthy', $this->mrv()->climateSnapshot()['status']);
    }

    // --- completeness ---------------------------------------------------------------------------------------------------

    public function test_completeness_thresholds_on_the_share_of_readings_without_a_temperature(): void
    {
        $this->city(1);
        $db = DB::connection('weather')->table('live_conditions_log');
        // Above 5% warns and above 20% is critical, so 1 of 20 (5%) is still healthy and 4 of 20 (20%) only warns.
        foreach ([[0, 'healthy'], [1, 'healthy'], [2, 'warning'], [4, 'warning'], [5, 'critical']] as [$missing, $status]) {
            $db->delete();
            for ($i = 0; $i < 20; $i++) {
                $this->live(1, sprintf('2026-10-18 %02d:%02d:00', 5 + intdiv($i, 4), ($i % 4) * 15), $i < $missing ? null : 70.0);
            }
            $check = $this->mrv()->weatherCompleteness();
            $this->assertSame($status, $check['status'], "{$missing} of 20 missing");
            $this->assertSame(round(100 * $missing / 20, 1), $check['value']);
            $this->assertSame('percent', $check['unit']);
        }
    }

    public function test_completeness_warns_about_a_city_that_went_silent(): void
    {
        $this->city(1);
        $this->city(7, 'Big Bear City');
        $this->live(1, '2026-10-18 12:00:00');
        $this->live(7, '2026-10-14 12:00:00');   // reported within the week, nothing in 24 hours

        $check = $this->mrv()->weatherCompleteness();

        $this->assertSame('warning', $check['status']);
        $this->assertSame('1 city has no observation in 24 hours', $check['message']);
    }

    public function test_completeness_is_critical_with_no_observation_in_a_day(): void
    {
        $this->city(1);
        $this->live(1, '2026-10-16 12:00:00');

        $this->assertSame('critical', $this->mrv()->weatherCompleteness()['status']);
    }

    public function test_open_meteo_calls_add_up_the_tracked_budgets_and_climate_pairs(): void
    {
        $weather = DB::connection('weather');
        $weather->table('backfill_budget')->insert([['budget_date' => '2026-10-18', 'equivalent_calls_spent' => 1200.5], ['budget_date' => '2026-10-17', 'equivalent_calls_spent' => 9999]]);
        $weather->table('airquality_budget')->insert(['budget_date' => '2026-10-18', 'equivalent_calls_spent' => 300]);
        $weather->table('climate_fetch_progress')->insert([
            ['snapshot' => 's', 'city_id' => 1, 'model' => 'a', 'completed_at' => '2026-10-18 03:00:00'],
            ['snapshot' => 's', 'city_id' => 1, 'model' => 'b', 'completed_at' => '2026-10-18 03:00:00'],
            ['snapshot' => 's', 'city_id' => 1, 'model' => 'c', 'completed_at' => '2026-10-17 23:59:59'],
        ]);

        $this->assertEqualsWithDelta(1200.5 + 300 + 2 * 480, $this->mrv()->openMeteoCallsToday(), 0.001);
    }

    // --- ingestion ------------------------------------------------------------------------------------------------------

    private function source(array $attributes = []): Source
    {
        return Source::create($attributes + ['name' => 'Feed '.Str::random(4), 'source_type' => 'ics', 'collector_type' => 'ics', 'active' => true]);
    }

    public function test_ics_fetch_age_thresholds(): void
    {
        foreach ([[20, 'healthy'], [100, 'warning'], [200, 'critical']] as [$minutes, $status]) {
            Source::query()->delete();
            // The application writes timestamps in its own zone (UTC here).
            $this->source(['last_success_at' => CarbonImmutable::parse('2026-10-18 20:00:00', 'UTC')->subMinutes($minutes)]);
            $this->assertSame($status, $this->mrv()->ingestIcsAge()['status'], "{$minutes} minutes");
        }
    }

    public function test_ics_fetch_age_is_measured_in_the_application_zone(): void
    {
        config(['app.timezone' => 'America/New_York']);
        Source::query()->delete();
        $this->source(['last_success_at' => '2026-10-18 15:40:00']);   // 15:40 Eastern = 19:40 UTC

        $check = $this->mrv()->ingestIcsAge();

        $this->assertSame('healthy', $check['status']);
        $this->assertSame(0.3, $check['value']);
    }

    public function test_ics_fetch_age_ignores_inactive_and_other_collectors(): void
    {
        $this->assertSame('inactive', $this->mrv()->ingestIcsAge()['status'], 'no sources at all');

        $this->source(['active' => false, 'last_success_at' => '2026-10-18 19:50:00']);
        $this->source(['collector_type' => 'tribe_events', 'last_success_at' => '2026-10-18 19:50:00']);
        $this->assertSame('inactive', $this->mrv()->ingestIcsAge()['status']);

        $this->source(['last_success_at' => null]);
        $this->assertSame('critical', $this->mrv()->ingestIcsAge()['status'], 'active but never fetched');
    }

    public function test_failing_sources_are_counted_among_the_active_ics_sources(): void
    {
        $this->source(['last_error' => null]);
        $this->assertSame('healthy', $this->mrv()->sourcesFailing()['status']);

        $this->source(['last_error' => 'HTTP 500']);
        $this->source(['last_error' => 'timeout', 'active' => false]);
        $this->source(['last_error' => 'x', 'collector_type' => 'tribe_events']);
        $check = $this->mrv()->sourcesFailing();

        $this->assertSame('warning', $check['status']);
        $this->assertSame(1, $check['value']);
        $this->assertSame('1 active ICS source failed on its last fetch', $check['message']);
    }

    public function test_collector_metrics(): void
    {
        $this->source(['collector_type' => 'tribe_events', 'last_success_at' => '2026-10-18 14:00:00']);
        $this->source(['collector_type' => 'tribe_events', 'last_success_at' => '2026-10-17 14:00:00']);
        $this->source(['collector_type' => 'tribe_events', 'active' => false]);
        $this->source(['collector_type' => 'manual', 'active' => false]);

        $this->assertSame(6.0, $this->mrv()->collectorAge('tribe_events'));
        $this->assertNull($this->mrv()->collectorAge('squarespace_events'));
        $this->assertSame(1, $this->mrv()->sourcesDeactivated(), 'manual records are not ingestion sources');
    }

    // --- community ------------------------------------------------------------------------------------------------------

    public function test_the_invite_gate_check_depends_on_the_environment(): void
    {
        config(['app.invite_only' => true]);
        $this->assertSame('healthy', $this->mrv()->inviteGate('staging')['status']);

        config(['app.invite_only' => false]);
        $off = $this->mrv()->inviteGate('staging');
        $this->assertSame('critical', $off['status']);
        $this->assertSame('exposure_invite_gate', $off['key']);

        $this->assertSame('inactive', $this->mrv()->inviteGate('production')['status']);
        $this->assertSame('inactive', $this->mrv()->inviteGate('development')['status']);
    }

    private function event(array $attributes = []): Event
    {
        $created = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);
        $event = Event::create($attributes + ['title' => 'Event', 'slug' => Str::random(10), 'start_at' => '2026-10-25 18:00:00', 'timezone' => 'America/Los_Angeles']);

        if ($created !== null) {
            $event->forceFill(['created_at' => $created])->save();
        }

        return $event;
    }

    public function test_upcoming_published_events(): void
    {
        $zero = $this->mrv()->eventsUpcomingPublished();
        $this->assertSame('warning', $zero['status']);
        $this->assertSame(0, $zero['value']);

        $this->travelTo('2026-10-18 20:00:00');
        $this->event(['editorial_status' => 'published', 'status' => 'scheduled', 'start_at' => '2026-10-25 18:00:00']);
        $this->event(['editorial_status' => 'published', 'status' => 'scheduled', 'start_at' => '2026-10-01 18:00:00']);   // past
        $this->event(['editorial_status' => 'pending_review', 'status' => 'candidate']);                                    // not published

        $check = $this->mrv()->eventsUpcomingPublished();
        $this->assertSame('healthy', $check['status']);
        $this->assertSame(1, $check['value']);
    }

    public function test_moderation_and_submission_counts(): void
    {
        $this->event(['editorial_status' => 'pending_review', 'created_at' => '2026-10-10 20:00:00']);
        $this->event(['editorial_status' => 'unreviewed', 'created_at' => '2026-10-17 20:00:00']);
        $this->event(['editorial_status' => 'published']);
        $this->event(['editorial_status' => 'rejected']);

        $this->assertSame(2, $this->mrv()->moderationBacklog());
        $this->assertSame(8.0, $this->mrv()->moderationOldestAgeDays());

        $this->assertNull($this->mrv()->spamRateWeek(), 'no submissions, no rate');
        foreach ([['2026-10-17 10:00:00', 'pending'], ['2026-10-16 10:00:00', 'spam'], ['2026-10-15 10:00:00', 'approved'], ['2026-10-14 10:00:00', 'spam'], ['2026-09-01 10:00:00', 'spam']] as [$created, $status]) {
            $submission = PublicSubmission::create(['submission_type' => 'event', 'submitted_data' => ['title' => 'x'], 'status' => $status]);
            $submission->forceFill(['created_at' => $created])->save();
        }
        $this->assertSame(4, $this->mrv()->submissionsWeek());
        $this->assertSame(50.0, $this->mrv()->spamRateWeek());
    }

    public function test_nothing_waiting_for_moderation_has_no_age(): void
    {
        $this->assertSame(0, $this->mrv()->moderationBacklog());
        $this->assertNull($this->mrv()->moderationOldestAgeDays());
    }

    public function test_channel_and_curator_counts(): void
    {
        $a = Channel::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        Channel::create(['name' => 'B', 'slug' => 'b', 'status' => 'draft']);
        $b = Channel::create(['name' => 'C', 'slug' => 'c', 'status' => 'active']);
        $curator = User::factory()->create();
        $other = User::factory()->create();
        CuratorAssignment::create(['channel_id' => $a->id, 'user_id' => $curator->id, 'status' => 'active']);
        CuratorAssignment::create(['channel_id' => $b->id, 'user_id' => $curator->id, 'status' => 'active']);
        CuratorAssignment::create(['channel_id' => $a->id, 'user_id' => $other->id, 'status' => 'ended']);

        $this->assertSame(2, $this->mrv()->channelsActive());
        $this->assertSame(1, $this->mrv()->curatorsActive(), 'one person on two channels counts once; an ended assignment not at all');
    }

    // --- mail and data --------------------------------------------------------------------------------------------------

    private function logDir(string $contents): string
    {
        $dir = sys_get_temp_dir().'/status-health-log-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/laravel.log', $contents);

        return $dir;
    }

    public function test_mail_failures_count_mailer_exceptions_in_the_last_day(): void
    {
        $dir = $this->logDir(
            "[2026-10-18 18:00:00] production.ERROR: Unable to send an email: Invalid token {\"exception\":\"[object] (Symfony\\\\Component\\\\Mailer\\\\Exception\\\\HttpTransportException(code: 401): x at /vendor/y.php:1)\n[stacktrace]\n#0 {main}\n\"}\n"
            ."[2026-10-18 09:00:00] production.ERROR: Another mailer failure Symfony\\Component\\Mailer\\Exception\\TransportException\n"
            ."[2026-10-18 19:00:00] production.ERROR: Something unrelated\n#0 /vendor/symfony/mailer/Foo.php\n"
            ."[2026-10-16 19:00:00] production.ERROR: Old mail failure Symfony\\Component\\Mailer\\Exception\\TransportException\n"
            ."[2026-10-18 19:30:00] production.INFO: Mailer Exception text in an info line Mailer\\Exception\n"
        );

        $check = $this->mrv($dir)->mailFailures();

        $this->assertSame('warning', $check['status']);
        $this->assertSame(2, $check['value']);
        $this->assertSame('2 mail sends failed in the last 24 hours (at least)', $check['message']);
        $this->assertStringNotContainsString('Invalid token', json_encode($check), 'no log text leaves the check');
    }

    public function test_mail_failures_are_zero_without_a_log_or_without_failures(): void
    {
        $this->assertSame('healthy', $this->mrv(sys_get_temp_dir().'/no-such-log-dir')->mailFailures()['status']);
        $this->assertSame('healthy', $this->mrv($this->logDir("[2026-10-18 19:00:00] production.INFO: fine\n"))->mailFailures()['status']);
    }

    public function test_the_data_endpoints_still_compress(): void
    {
        $this->assertSame('healthy', $this->mrv()->dataCompression()['status']);
    }

    // --- one failing check does not take the others with it -------------------------------------------------------------

    public function test_a_failing_check_becomes_unknown_alone(): void
    {
        Schema::connection('weather')->drop('climate_snapshots');   // climateSnapshot() now throws

        $check = $this->mrv()->guard('weather_freshness_climate_snapshot', 'Climate snapshot data', fn () => $this->mrv()->climateSnapshot());

        $this->assertSame('unknown', $check['status']);
        $this->assertSame('Check failed to run', $check['message']);
        $this->assertSame('weather_freshness_climate_snapshot', $check['key']);
    }

    // --- throttling counts (daily counters written by the cron scripts) -------------------------------------------------

    private function writeCounterFile(string $file, array|string $data): void
    {
        file_put_contents("{$this->statusDir}/{$file}", is_array($data) ? json_encode($data) : $data);
    }

    private function clockAt(string $utc): MrvChecks
    {
        return new MrvChecks(CarbonImmutable::parse($utc, 'UTC'));
    }

    public function test_quota_stops_read_the_counter_file_for_todays_utc_date(): void
    {
        $this->writeCounterFile('counter_quota_stops.json', ['counter' => 'quota_stops', 'date' => '2026-10-18', 'count' => 3, 'updated_at' => '2026-10-18T14:05:09Z', 'by_job' => ['daily_update' => 2, 'backfill_worker' => 1]]);

        $metric = $this->mrv()->quotaStopsMetric();

        $this->assertSame('quota_stops_today', $metric['key']);
        $this->assertSame('Open-Meteo runs stopped by the daily quota today', $metric['label']);
        $this->assertSame(3, $metric['value']);
        $this->assertSame('count', $metric['unit']);
        $this->assertSame('2026-10-18T14:05:09Z', $metric['observed_at'], 'the time of the last update to the counter');
    }

    public function test_a_missing_counter_file_reads_zero_observed_now(): void
    {
        $metric = $this->mrv()->quotaStopsMetric();

        $this->assertSame(0, $metric['value']);
        $this->assertSame('2026-10-18T20:00:00Z', $metric['observed_at'], 'the report time when it reads 0');
        $this->assertSame(0, $this->mrv()->usgsThrottledMetric()['value'], 'no groundwater status file at all');
    }

    public function test_a_counter_left_from_an_earlier_utc_day_reads_zero(): void
    {
        $this->writeCounterFile('counter_quota_stops.json', ['date' => '2026-10-17', 'count' => 5, 'updated_at' => '2026-10-17T23:30:00Z']);

        $metric = $this->mrv()->quotaStopsMetric();

        $this->assertSame(0, $metric['value']);
        $this->assertSame('2026-10-18T20:00:00Z', $metric['observed_at'], 'not the stale file time');
    }

    public function test_the_counter_rolls_over_at_midnight_utc_not_pacific_midnight(): void
    {
        $this->writeCounterFile('counter_quota_stops.json', ['date' => '2026-10-18', 'count' => 4, 'updated_at' => '2026-10-18T22:00:00Z']);

        $this->assertSame(4, $this->clockAt('2026-10-18 23:59:59')->quotaStopsMetric()['value']);
        $this->assertSame(0, $this->clockAt('2026-10-19 00:00:00')->quotaStopsMetric()['value'], 'the next UTC day, 17:00 Pacific on the 18th');
        $this->assertSame(4, $this->clockAt('2026-10-18 07:00:00')->quotaStopsMetric()['value'], 'midnight Pacific (07:00 UTC) changes nothing: the day is the UTC day');
        $this->assertSame(0, $this->clockAt('2026-10-17 23:59:59')->quotaStopsMetric()['value'], 'a file dated after the report (clock moved back) is not today either');
    }

    public function test_a_negative_or_missing_count_never_reads_below_zero(): void
    {
        $this->writeCounterFile('counter_quota_stops.json', ['date' => '2026-10-18', 'count' => -2, 'updated_at' => '2026-10-18T10:00:00Z']);
        $this->assertSame(0, $this->mrv()->quotaStopsMetric()['value']);

        $this->writeCounterFile('counter_quota_stops.json', ['date' => '2026-10-18', 'updated_at' => '2026-10-18T10:00:00Z']);
        $this->assertSame(0, $this->mrv()->quotaStopsMetric()['value']);
    }

    public function test_an_unreadable_counter_or_folder_leaves_the_metric_out(): void
    {
        $this->writeCounterFile('counter_quota_stops.json', '{not json');
        $this->assertNull($this->mrv()->quotaStopsMetric(), 'damaged file');

        config(['services.status_health.status_dir' => null]);
        $this->assertNull($this->mrv()->quotaStopsMetric(), 'folder not configured');
        $this->assertNull($this->mrv()->usgsThrottledMetric());

        config(['services.status_health.status_dir' => $this->statusDir.'/nowhere']);
        $this->assertNull($this->mrv()->quotaStopsMetric(), 'folder does not exist');
    }

    public function test_usgs_throttling_reads_the_counts_object_of_the_groundwater_status_file(): void
    {
        $this->writeJobStatus('groundwater', [
            'last_status' => 'failure', 'last_success_at' => '2026-10-17T06:10:03Z', 'consecutive_failures' => 1, 'interval_seconds' => 86400,
            'counts' => ['usgs_throttled' => ['date' => '2026-10-18', 'count' => 1, 'updated_at' => '2026-10-18T06:10:04Z']],
        ]);

        $metric = $this->mrv()->usgsThrottledMetric();

        $this->assertSame('usgs_throttled_today', $metric['key']);
        $this->assertSame('Groundwater runs stopped by the USGS rate limit today', $metric['label']);
        $this->assertSame(1, $metric['value']);
        $this->assertSame('count', $metric['unit']);
        $this->assertSame('2026-10-18T06:10:04Z', $metric['observed_at']);
    }

    public function test_usgs_throttling_reads_zero_for_an_old_day_an_empty_counts_object_or_an_older_status_file(): void
    {
        $this->writeJobStatus('groundwater', ['last_status' => 'success', 'counts' => ['usgs_throttled' => ['date' => '2026-10-17', 'count' => 1, 'updated_at' => '2026-10-17T06:10:04Z']]]);
        $this->assertSame(0, $this->mrv()->usgsThrottledMetric()['value'], 'yesterday');

        $this->writeJobStatus('groundwater', ['last_status' => 'success', 'counts' => (object) []]);
        $this->assertSame(0, $this->mrv()->usgsThrottledMetric()['value'], 'counts is {}');
        $this->assertSame('2026-10-18T20:00:00Z', $this->mrv()->usgsThrottledMetric()['observed_at']);

        $this->writeJobStatus('groundwater', ['last_status' => 'success']);
        $this->assertSame(0, $this->mrv()->usgsThrottledMetric()['value'], 'a file written before counts existed');
    }

    public function test_a_damaged_groundwater_status_file_leaves_the_usgs_metric_out(): void
    {
        $this->writeCounterFile('groundwater.json', '{not json');
        $this->assertNull($this->mrv()->usgsThrottledMetric());

        $this->writeJobStatus('groundwater', ['counts' => ['usgs_throttled' => 'twice']]);
        $this->assertNull($this->mrv()->usgsThrottledMetric(), 'a counter that is not an object');
    }
}
