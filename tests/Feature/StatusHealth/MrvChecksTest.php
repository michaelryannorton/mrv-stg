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

    /** Rows for every day from $from to $to (inclusive), for each city given, except the (city, date) pairs in $skip. */
    private function dailyRows(array $cities, string $from, string $to, array $skip = []): void
    {
        $db = DB::connection('weather')->table('weather_daily');
        for ($day = CarbonImmutable::parse($from); $day->format('Y-m-d') <= $to; $day = $day->addDay()) {
            foreach ($cities as $city) {
                if (! in_array([$city, $day->format('Y-m-d')], $skip, true)) {
                    $db->insert(['city_id' => $city, 'date' => $day->format('Y-m-d')]);
                }
            }
        }
    }

    // Today in Pacific is 2026-10-18, so the coverage window is 2026-09-18 to 2026-10-15 and days before 2026-10-14 are too old to refill.

    public function test_daily_coverage_is_healthy_when_every_city_has_every_day_in_the_window(): void
    {
        $this->city(1);
        $this->city(500, 'Grid point', 'grid');
        $this->city(900, 'Soil point', 'soil_grid');   // never gets weather rows, must not count
        $this->dailyRows([1, 500], '2026-09-18', '2026-10-15');

        $check = $this->mrv()->dailyCoverage();

        $this->assertSame('healthy', $check['status']);
        $this->assertSame(0, $check['value']);
        $this->assertSame('weather_daily_coverage', $check['key']);
    }

    public function test_daily_coverage_warns_about_a_recent_gap_the_daily_job_can_still_refill(): void
    {
        $this->city(1);
        $this->city(500, 'Grid point', 'grid');
        $this->dailyRows([1, 500], '2026-09-18', '2026-10-15', [[500, '2026-10-15'], [500, '2026-10-14']]);

        $check = $this->mrv()->dailyCoverage();

        $this->assertSame('warning', $check['status']);
        $this->assertSame(2, $check['value']);
        $this->assertSame('count', $check['unit']);
        $this->assertSame('2 city-days have no daily weather row: 2026-10-14 (1), 2026-10-15 (1)', $check['message']);
    }

    public function test_daily_coverage_is_critical_when_a_missing_day_is_too_old_for_the_daily_job(): void
    {
        $this->city(1);
        $this->city(500, 'Grid point', 'grid');
        $this->city(501, 'Grid point 2', 'grid');
        $this->dailyRows([1, 500, 501], '2026-09-18', '2026-10-15', [[500, '2026-10-10'], [501, '2026-10-10'], [500, '2026-10-15']]);

        $check = $this->mrv()->dailyCoverage();

        $this->assertSame('critical', $check['status']);
        $this->assertSame(3, $check['value']);
        $this->assertSame('3 city-days have no daily weather row: 2026-10-10 (2), 2026-10-15 (1); the daily job no longer refills days before 2026-10-14', $check['message']);
    }

    public function test_daily_coverage_ignores_days_outside_the_window(): void
    {
        $this->city(1);
        $this->city(500, 'Grid point', 'grid');
        // Complete from 18 September to 15 October; the days before and the 16th and 17th (the archive's lag) are empty and must not count.
        $this->dailyRows([1, 500], '2026-09-18', '2026-10-15');

        $this->assertSame('healthy', $this->mrv()->dailyCoverage()['status']);
    }

    public function test_daily_coverage_lists_at_most_six_days(): void
    {
        $this->city(1);
        $this->city(500, 'Grid point', 'grid');
        $this->dailyRows([1], '2026-09-18', '2026-10-15');

        $check = $this->mrv()->dailyCoverage();

        $this->assertSame(28, $check['value']);
        $this->assertStringEndsWith(', ...; the daily job no longer refills days before 2026-10-14', $check['message']);
        $this->assertSame(6, substr_count($check['message'], ' ('));
    }

    public function test_daily_coverage_is_critical_with_no_cities(): void
    {
        $this->assertSame('critical', $this->mrv()->dailyCoverage()['status']);
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

    private function writeOpenMeteoCounter(array $overrides = []): void
    {
        file_put_contents("{$this->statusDir}/counter_openmeteo.json", json_encode($overrides + [
            'counter' => 'openmeteo', 'date' => '2026-10-18', 'requests' => 514, 'equivalent_calls' => 3012.44, 'updated_at' => '2026-10-18T19:55:00Z',
            'outcomes' => ['ok' => 508, 'daily' => 1, 'hourly' => 2, 'minutely' => 3, 'other' => 0],
            'by_job' => ['daily_update' => ['requests' => 477, 'equivalent_calls' => 1717.2]],
            'first_daily_refusal' => ['at' => '2026-10-18T14:00:00Z', 'job' => 'backfill_worker', 'requests_before' => 498, 'equivalent_calls_before' => 2511.3],
            'last_daily_refusal' => ['date' => '2026-10-18', 'at' => '2026-10-18T14:00:00Z', 'job' => 'backfill_worker', 'requests_before' => 498, 'equivalent_calls_before' => 2511.3],
        ]));
    }

    public function test_open_meteo_counter_file_replaces_the_estimate_when_it_exists(): void
    {
        // The budget tables say 1500.5 calls; the counter, which sees every request, says 3012.4.
        DB::connection('weather')->table('backfill_budget')->insert(['budget_date' => '2026-10-18', 'equivalent_calls_spent' => 1500.5]);
        $this->writeOpenMeteoCounter();

        $this->assertEqualsWithDelta(3012.4, $this->mrv()->openMeteoCallsToday(), 0.001);
        $this->assertSame(514, $this->mrv()->openMeteoRequestsToday());
        $this->assertSame(6, $this->mrv()->openMeteoRefusalsToday(), 'daily, hourly and per-minute refusals; not other errors');
        $this->assertEqualsWithDelta(2511.3, $this->mrv()->openMeteoObservedCeiling(), 0.001);
    }

    public function test_open_meteo_counter_from_an_earlier_day_reads_zero_but_keeps_the_ceiling(): void
    {
        $this->writeOpenMeteoCounter(['date' => '2026-10-17']);

        $this->assertEqualsWithDelta(0.0, $this->mrv()->openMeteoCallsToday(), 0.001);
        $this->assertSame(0, $this->mrv()->openMeteoRequestsToday());
        $this->assertSame(0, $this->mrv()->openMeteoRefusalsToday());
        $this->assertEqualsWithDelta(2511.3, $this->mrv()->openMeteoObservedCeiling(), 0.001, 'the latest daily refusal is carried across days');
    }

    public function test_open_meteo_counter_missing_or_damaged_falls_back_to_the_estimate_and_leaves_the_new_metrics_out(): void
    {
        DB::connection('weather')->table('backfill_budget')->insert(['budget_date' => '2026-10-18', 'equivalent_calls_spent' => 1500.5]);

        $this->assertEqualsWithDelta(1500.5, $this->mrv()->openMeteoCallsToday(), 0.001, 'no counter file');
        $this->assertNull($this->mrv()->openMeteoRequestsToday());
        $this->assertNull($this->mrv()->openMeteoRefusalsToday());
        $this->assertNull($this->mrv()->openMeteoObservedCeiling());

        file_put_contents("{$this->statusDir}/counter_openmeteo.json", '{not json');
        $this->assertEqualsWithDelta(1500.5, $this->mrv()->openMeteoCallsToday(), 0.001, 'a damaged file');
        $this->assertNull($this->mrv()->openMeteoRequestsToday());
    }

    public function test_open_meteo_counter_without_a_refusal_has_no_ceiling(): void
    {
        $this->writeOpenMeteoCounter(['first_daily_refusal' => null, 'last_daily_refusal' => null, 'outcomes' => ['ok' => 514, 'daily' => 0, 'hourly' => 0, 'minutely' => 0, 'other' => 0]]);

        $this->assertNull($this->mrv()->openMeteoObservedCeiling());
        $this->assertSame(0, $this->mrv()->openMeteoRefusalsToday());
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

    // --- impact and action (contract revision 10) -------------------------------------------------------------------------

    public function test_a_check_that_is_not_healthy_carries_its_impact_and_action(): void
    {
        $warning = MrvChecks::withMeaning(['key' => 'weather_daily_coverage', 'label' => 'x', 'status' => 'warning']);
        $this->assertSame('risk', $warning['impact']);
        $critical = MrvChecks::withMeaning(['key' => 'weather_daily_coverage', 'label' => 'x', 'status' => 'critical']);
        $this->assertSame('visible', $critical['impact']);
        $this->assertSame($warning['action'], $critical['action']);

        $unknown = MrvChecks::withMeaning(['key' => 'weather_completeness', 'label' => 'x', 'status' => 'unknown']);
        $this->assertSame('monitoring', $unknown['impact']);
        $this->assertStringContainsString('laravel.log', $unknown['action']);
    }

    public function test_a_healthy_or_inactive_check_and_a_check_without_an_entry_are_unchanged(): void
    {
        foreach (['healthy', 'inactive'] as $status) {
            $result = ['key' => 'weather_daily_coverage', 'label' => 'x', 'status' => $status];
            $this->assertSame($result, MrvChecks::withMeaning($result));
        }
        $other = ['key' => 'exposure_invite_gate', 'label' => 'x', 'status' => 'critical'];
        $this->assertSame($other, MrvChecks::withMeaning($other), 'the dashboard has its own text for exposure_ checks');
    }

    public function test_every_own_check_entry_is_valid_and_the_action_fits_the_dashboard(): void
    {
        foreach (MrvChecks::MEANING as $key => [$atWarning, $atCritical, $action]) {
            $this->assertContains($atWarning, ['visible', 'risk', 'monitoring'], $key);
            $this->assertContains($atCritical, ['visible', 'risk', 'monitoring'], $key);
            $this->assertLessThanOrEqual(160, mb_strlen($action), "$key: the dashboard shows up to 160 characters");
            $this->assertNotSame('', trim($action), $key);
        }
    }

    public function test_guard_adds_the_fields_to_a_check_that_throws_and_to_one_that_returns_a_warning(): void
    {
        $thrown = $this->mrv()->guard('weather_completeness', 'Live data completeness', fn () => throw new \RuntimeException('x'));
        $this->assertSame('unknown', $thrown['status']);
        $this->assertSame('monitoring', $thrown['impact']);

        $warned = $this->mrv()->guard('sources_failing', 'Calendar sources failing', fn () => ['key' => 'sources_failing', 'label' => 'x', 'status' => 'warning']);
        $this->assertSame('risk', $warned['impact']);
        $this->assertArrayHasKey('action', $warned);
    }

    // --- backfill checks (plan C) ---------------------------------------------------------------------------------------------

    /** Three backfill targets (two named, one grid) and one of the original 11, which the backfill skips. The clock gives 2026, so 11 years. */
    private function backfillTargets(): void
    {
        $this->city(1, 'Victorville');
        $this->city(101, 'Wrightwood');
        $this->city(102, 'Lake Arrowhead');
        $this->city(500, 'Grid point', 'grid');
    }

    private function chunk(int $city, int $year, string $completedEastern, int $rows = 0, string $kind = 'backfill'): void
    {
        DB::connection('weather')->table('backfill_progress')->insert(['city_id' => $city, 'year' => $year, 'kind' => $kind, 'rows_sent' => $rows, 'completed_at' => $completedEastern]);
    }

    public function test_the_backfill_checks_are_inactive_with_no_targets(): void
    {
        $this->city(1, 'Victorville');

        $this->assertSame('inactive', $this->mrv()->backfillProgress()['status']);
        $this->assertSame('inactive', $this->mrv()->backfillIntegrity()['status']);
        $this->assertSame(0.0, $this->mrv()->backfillChunksRemaining());
    }

    public function test_backfill_progress_is_healthy_just_after_a_chunk_and_counts_what_is_left(): void
    {
        $this->backfillTargets();
        // 2026-10-18 20:00 UTC is 16:00 EDT. 15:30 EDT is 19:30 UTC: half an hour ago (read as UTC it would be 4.5 hours).
        $this->chunk(101, 2026, '2026-10-18 15:30:00');
        $this->chunk(101, 2025, '2026-10-15 12:00:00');
        $this->chunk(500, 2016, '2026-09-01 12:00:00');
        $this->chunk(1, 2026, '2026-10-18 15:45:00'); // an original city: not a target, not counted
        $this->chunk(101, 2024, '2026-10-18 15:45:00', 0, 'correction'); // another kind: not counted

        $check = $this->mrv()->backfillProgress();
        $this->assertSame('healthy', $check['status']);
        $this->assertSame(0.5, $check['value']);
        $this->assertNull($check['message']);
        $this->assertSame(3.0, $this->mrv()->backfillChunksDone());
        $this->assertSame(30.0, $this->mrv()->backfillChunksRemaining(), '3 targets x 11 years = 33 chunks, 3 done');
        $this->assertSame(0.3, $this->mrv()->backfillRate7d(), '2 chunks in the last 7 days, over 7 days: 0.2857, to one decimal');
    }

    public function test_backfill_progress_warns_and_goes_critical_with_the_remaining_count_in_the_message(): void
    {
        $this->backfillTargets();
        $this->chunk(101, 2026, '2026-10-16 02:00:00'); // 2026-10-16 06:00 UTC: 62 hours before the clock
        $warning = $this->mrv()->backfillProgress();
        $this->assertSame('warning', $warning['status']);
        $this->assertSame(62.0, $warning['value']);
        $this->assertStringContainsString('32 of 33 chunks remain', $warning['message']);

        DB::connection('weather')->table('backfill_progress')->delete();
        $this->chunk(101, 2026, '2026-10-13 04:00:00'); // 2026-10-13 08:00 UTC: 132 hours
        $this->assertSame('critical', $this->mrv()->backfillProgress()['status']);

        DB::connection('weather')->table('backfill_progress')->delete();
        $this->chunk(101, 2026, '2026-10-16 08:00:00'); // 2026-10-16 12:00 UTC: 56 hours, a normal gap after a day with no chunk
        $this->assertSame('healthy', $this->mrv()->backfillProgress()['status']);
    }

    public function test_backfill_progress_with_no_chunk_done_warns_and_a_finished_backfill_is_inactive(): void
    {
        $this->backfillTargets();
        $none = $this->mrv()->backfillProgress();
        $this->assertSame('warning', $none['status']);
        $this->assertStringContainsString('No backfill chunk has been completed', $none['message']);

        foreach ([101, 102, 500] as $city) {
            for ($year = 2016; $year <= 2026; $year++) {
                $this->chunk($city, $year, '2026-10-18 15:00:00');
            }
        }
        $done = $this->mrv()->backfillProgress();
        $this->assertSame('inactive', $done['status']);
        $this->assertSame('Backfill complete', $done['message']);
        $this->assertNull($this->mrv()->backfillEtaDays());
    }

    public function test_backfill_eta_follows_the_seven_day_rate(): void
    {
        $this->backfillTargets();
        for ($i = 0; $i < 7; $i++) {
            $this->chunk(101, 2016 + $i, '2026-10-17 12:00:00'); // 7 chunks in the last 7 days: 1 a day
        }
        $this->assertSame(1.0, $this->mrv()->backfillRate7d());
        $this->assertSame(26.0, $this->mrv()->backfillEtaDays(), '33 - 7 = 26 chunks at 1 a day');

        DB::connection('weather')->table('backfill_progress')->delete();
        $this->chunk(101, 2016, '2026-09-01 12:00:00');
        $this->assertNull($this->mrv()->backfillEtaDays(), 'nothing in the last 7 days');
    }

    public function test_the_backfill_clock_is_read_as_eastern_time_in_winter_too(): void
    {
        $this->backfillTargets();
        $winter = new MrvChecks(CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC')); // 07:00 EST
        $this->chunk(101, 2026, '2026-11-02 05:00:00'); // 10:00 UTC
        $this->assertSame(2.0, $winter->backfillProgress()['value']);
    }

    public function test_backfill_integrity_is_healthy_when_every_chunk_holds_its_rows_or_more(): void
    {
        $this->backfillTargets();
        $this->dailyRows([101], '2020-01-01', '2020-01-10');
        $this->chunk(101, 2020, '2026-09-01 12:00:00', 10);
        $this->chunk(101, 2021, '2026-09-01 12:00:00', 0);
        $this->chunk(101, 2022, '2026-09-01 12:00:00', 0);
        $this->dailyRows([500], '2026-01-01', '2026-01-12');
        $this->chunk(500, 2026, '2026-09-01 12:00:00', 8); // 12 stored, 8 sent: the daily job added days

        $check = $this->mrv()->backfillIntegrity();
        $this->assertSame('healthy', $check['status']);
        $this->assertSame(0, $check['value']);
    }

    public function test_backfill_integrity_warns_on_one_short_chunk_among_many_and_is_critical_above_one_percent(): void
    {
        $this->backfillTargets();
        for ($year = 2016; $year <= 2026; $year++) {
            foreach ([101, 102, 500] as $city) {
                $this->chunk($city, $year, '2026-09-01 12:00:00', 0);
            }
        }
        for ($i = 0; $i < 9; $i++) {
            for ($year = 2016; $year <= 2026; $year++) {
                // pad to 132 chunks (above 100) with other cities' rows (not targets, still kind backfill)
                $this->chunk(900 + $i, $year, '2026-09-01 12:00:00', 0);
            }
        }
        $this->dailyRows([101], '2020-01-01', '2020-01-03');
        DB::connection('weather')->table('backfill_progress')->where('city_id', 101)->where('year', 2020)->update(['rows_sent' => 5]);

        $warning = $this->mrv()->backfillIntegrity();
        $this->assertSame('warning', $warning['status'], '1 short chunk of 132 is 0.76%, under the 1% limit');
        $this->assertSame(1, $warning['value']);
        $this->assertStringContainsString('city 101 2020 (3 of 5)', $warning['message']);

        DB::connection('weather')->table('backfill_progress')->where('city_id', 102)->where('year', 2021)->update(['rows_sent' => 4]);
        $critical = $this->mrv()->backfillIntegrity();
        $this->assertSame('critical', $critical['status'], '2 short chunks of 132 is 1.5%');
        $this->assertSame(2, $critical['value']);
    }

    public function test_the_original_cities_list_matches_the_worker_script(): void
    {
        $worker = base_path('../api/cron/backfill_worker.php');
        if (! is_file($worker)) {
            $this->markTestSkipped('api/cron/backfill_worker.php is outside this repository.');
        }
        $this->assertSame(1, preg_match("/const ORIGINAL_11 = \[(.*?)\];/s", (string) file_get_contents($worker), $m));
        preg_match_all("/'([^']+)'/", $m[1], $names);
        $this->assertEqualsCanonicalizing($names[1], MrvChecks::BACKFILL_ORIGINAL_CITIES);
        $this->assertSame((int) 2016, MrvChecks::BACKFILL_START_YEAR);
        $this->assertStringContainsString('const START_YEAR = 2016;', (string) file_get_contents($worker));
    }

    // --- footprint -------------------------------------------------------------------------------------------------------

    /** A throwaway folder tree: path (relative) => contents, folders implied; an empty folder is a path ending in "/". */
    private function tree(array $files): string
    {
        $root = sys_get_temp_dir().'/status-health-tree-'.bin2hex(random_bytes(4));
        mkdir($root);
        foreach ($files as $path => $contents) {
            if (str_ends_with($path, '/')) {
                mkdir("{$root}/{$path}", 0777, true);

                continue;
            }
            @mkdir(dirname("{$root}/{$path}"), 0777, true);
            file_put_contents("{$root}/{$path}", $contents);
        }

        return $root;
    }

    public function test_the_file_counts_count_files_in_nested_folders_and_not_the_folders(): void
    {
        $base = $this->tree(['a.php' => 'x', 'vendor/p/b.php' => 'x', 'vendor/p/c.php' => 'x', 'storage/framework/sessions/s1' => 'x', 'storage/framework/sessions/s2' => 'x', 'empty/' => '']);
        $mrv = new MrvChecks(CarbonImmutable::parse('2026-10-18 20:00:00', 'UTC'), null, $base.'/status', $base, $base.'/storage/framework/sessions');
        mkdir($base.'/status');
        file_put_contents($base.'/status/earthquakes.json', '{}');

        $this->assertSame(6, $mrv->ownFileCount(), 'a, b, c, two sessions and the status file; folders are not files');
        $this->assertSame(2, $mrv->sessionsFileCount());
        $this->assertSame(1, $mrv->statusFolderFileCount());
    }

    public function test_the_file_counts_are_null_for_a_missing_folder_and_count_a_link_as_one_file(): void
    {
        $missing = sys_get_temp_dir().'/status-health-no-such-folder';
        $mrv = new MrvChecks(CarbonImmutable::parse('2026-10-18 20:00:00', 'UTC'), null, $missing, $missing, $missing);

        $this->assertNull($mrv->ownFileCount());
        $this->assertNull($mrv->sessionsFileCount());
        $this->assertNull($mrv->statusFolderFileCount());

        $base = $this->tree(['real/one' => 'x', 'real/two' => 'x']);
        symlink($base.'/real', $base.'/link');
        $this->assertSame(3, (new MrvChecks(CarbonImmutable::parse('2026-10-18 20:00:00', 'UTC'), null, null, $base))->ownFileCount(), 'the folder link is one entry and is not followed');
    }

    public function test_the_status_folder_count_is_null_when_no_folder_is_configured(): void
    {
        config(['services.status_health.status_dir' => '']);

        $this->assertNull((new MrvChecks(CarbonImmutable::parse('2026-10-18 20:00:00', 'UTC')))->statusFolderFileCount());
    }

    public function test_the_log_size_adds_the_files_in_the_log_folder_in_megabytes(): void
    {
        $dir = $this->logDir(str_repeat('a', 1048576));
        file_put_contents($dir.'/older.log', str_repeat('b', 524288));

        $this->assertSame(1.5, $this->mrv($dir)->storageLogsMb());
        $this->assertNull($this->mrv(sys_get_temp_dir().'/no-such-log-dir')->storageLogsMb());
    }

    public function test_the_database_sizes_are_null_where_the_database_is_not_mysql(): void
    {
        $this->assertNull($this->mrv()->weatherDbSizeMb());
        $this->assertNull($this->mrv()->appDbSizeMb());
    }

    public function test_the_daily_row_count_counts_the_table(): void
    {
        $this->assertSame(0, $this->mrv()->weatherDailyRows());
        $this->dailyRows([101], '2020-01-01', '2020-01-03');
        $this->assertSame(3, $this->mrv()->weatherDailyRows());
    }

    public function test_scheduler_failures_count_failed_commands_in_the_last_day_and_no_text_leaves(): void
    {
        $dir = $this->logDir(
            "[2026-10-18 04:00:39] production.ERROR: The command \"'/opt/alt/php84/usr/bin/php' 'artisan' ingest:ics > '/dev/null' 2>&1\" failed.\n"
            ."[2026-10-17 19:59:59] production.ERROR: The command \"old\" failed.\n"
            ."[2026-10-18 18:00:00] production.ERROR: The command \"'/opt/alt/php84/usr/bin/php' 'artisan' submissions:notify-pending\" failed.\n"
            ."[2026-10-18 19:00:00] production.ERROR: Something unrelated about the command line\n"
            ."[2026-10-18 19:10:00] production.INFO: The command text in an info line\n"
        );

        $count = $this->mrv($dir)->schedulerCommandFailures24h();

        $this->assertSame(2, $count, 'the old entry is outside 24 hours, the unrelated line and the info line are not scheduler failures');
        $this->assertSame(0, $this->mrv(sys_get_temp_dir().'/no-such-log-dir')->schedulerCommandFailures24h());
        $this->assertSame(0, $this->mrv($this->logDir("[2026-10-18 19:00:00] production.INFO: fine\n"))->schedulerCommandFailures24h());
    }
}
