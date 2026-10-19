<?php

namespace Tests\Feature\StatusHealth;

use App\Http\Controllers\StatusHealthController;
use App\Http\Middleware\InviteOnlyGate;
use App\Models\Source;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/**
 * The status dashboard's health endpoint (GET /_status/health), contract status-health/1: who may call it, what the report holds,
 * how a copy switches check groups off, and what the job status files do to the feed checks.
 */
class StatusHealthEndpointTest extends StatusHealthTestCase
{
    private const WRONG_TOKEN = '0000000000000000000000000000000000000000000000000000000000000000';

    private const STANDARD_KEYS = [
        'database', 'database_weather', 'migrations_pending', 'scheduler', 'storage_writable', 'debug_mode', 'app_env',
        'php_version_expected', 'vite_manifest', 'config_cache_stale', 'composer_install_pending', 'log_errors',
    ];

    private const WEATHER_KEYS = [
        'weather_freshness_live_conditions', 'weather_live_cities_stale', 'weather_freshness_daily_weather',
        'weather_freshness_air_quality', 'weather_freshness_climate_snapshot', 'weather_completeness',
        'job_earthquakes', 'job_groundwater', 'job_snow', 'job_drought',
    ];

    // --- who may call it --------------------------------------------------------------------------------------------------

    public function test_a_request_with_no_token_is_refused_as_missing(): void
    {
        $this->getJson('/_status/health')
            ->assertStatus(401)
            ->assertExactJson(['error' => 'unauthorized', 'reason' => 'missing']);
    }

    public function test_a_wrong_or_malformed_token_is_refused_as_invalid(): void
    {
        $this->withHeaders(['X-Status-Token' => self::WRONG_TOKEN])->getJson('/_status/health')
            ->assertStatus(401)->assertExactJson(['error' => 'unauthorized', 'reason' => 'invalid']);

        $this->withHeaders(['Authorization' => 'Bearer not-a-token'])->getJson('/_status/health')
            ->assertStatus(401)->assertExactJson(['error' => 'unauthorized', 'reason' => 'invalid']);

        // The right token's hash is not itself a token.
        $this->withHeaders(['Authorization' => 'Bearer '.hash('sha256', self::TOKEN)])->getJson('/_status/health')
            ->assertStatus(401)->assertJsonPath('reason', 'invalid');
    }

    public function test_the_right_token_is_accepted_in_either_header(): void
    {
        foreach ([['Authorization' => 'Bearer '.self::TOKEN], ['X-Status-Token' => self::TOKEN]] as $headers) {
            $response = $this->withHeaders($headers)->getJson('/_status/health');
            $response->assertOk()->assertJsonPath('schema', 'status-health/1');
        }

        // A header with the wrong token does not become valid because the other header is right (the controller reads Authorization first).
        $this->withHeaders(['Authorization' => 'Bearer '.self::WRONG_TOKEN, 'X-Status-Token' => self::TOKEN])->getJson('/_status/health')
            ->assertStatus(401);
    }

    public function test_the_next_hash_is_accepted_during_a_rotation(): void
    {
        config(['services.status_health.token_hash' => hash('sha256', self::WRONG_TOKEN), 'services.status_health.token_hash_next' => hash('sha256', self::TOKEN)]);

        $this->report()->assertOk();
    }

    /**
     * README step 2 says the route "answers 404" without a token hash, and the contract's table of responses says the same. The
     * controller (2.0.0) does not: it answers 401, "missing" or "invalid". Pinned here so the difference shows up when it is fixed.
     */
    public function test_without_a_configured_hash_nothing_is_accepted(): void
    {
        config(['services.status_health.token_hash' => null]);

        $this->getJson('/_status/health')->assertStatus(401)->assertJsonPath('reason', 'missing');
        $this->withHeaders(['X-Status-Token' => self::TOKEN])->getJson('/_status/health')->assertStatus(401)->assertJsonPath('reason', 'invalid');
    }

    // --- the gate and the web group -------------------------------------------------------------------------------------

    public function test_a_signed_out_request_is_not_redirected_when_the_invite_gate_is_on(): void
    {
        config(['app.invite_only' => true]);

        $this->getJson('/_status/health')->assertStatus(401);
        $this->report()->assertOk()->assertHeaderMissing('Location');

        // The same gate does redirect an ordinary page, so the test above means something.
        $this->get('/city-explorer')->assertRedirect(route('login'));
    }

    public function test_the_route_sits_outside_the_web_group(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === '_status/health');

        $this->assertNotNull($route);
        $middleware = $route->gatherMiddleware();
        $this->assertNotContains('web', $middleware);
        $this->assertNotContains(InviteOnlyGate::class, $middleware);
        $this->assertNotContains(StartSession::class, $middleware);
    }

    public function test_the_response_starts_no_session_and_sets_no_cookie(): void
    {
        $response = $this->report();

        $this->assertSame([], $response->baseResponse->headers->getCookies());
    }

    public function test_every_response_forbids_caching_and_indexing(): void
    {
        foreach ([$this->getJson('/_status/health'), $this->report()] as $response) {
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'));
            $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
        }
    }

    // --- what the report holds ------------------------------------------------------------------------------------------

    public function test_the_report_has_the_contract_shape(): void
    {
        $response = $this->report();

        $response->assertOk()
            ->assertJsonPath('schema', 'status-health/1')
            ->assertJsonPath('property', 'mojave-river-valley')
            ->assertJsonPath('environment', 'staging');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $response->json('generated_at'));
        $this->assertContains($response->json('status'), ['healthy', 'warning', 'critical', 'unknown']);
        $this->assertIsArray($response->json('stack'));
        $this->assertIsArray($response->json('metrics'));

        foreach ($response->json('checks') as $check) {
            $this->assertEqualsCanonicalizing(['key', 'label', 'status', 'observed_at', 'stale_after', 'value', 'unit', 'message'], array_keys($check), $check['key']);
            $this->assertContains($check['status'], ['healthy', 'warning', 'critical', 'unknown', 'inactive'], $check['key']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $check['observed_at'], $check['key']);
        }
        foreach ($response->json('metrics') as $metric) {
            $this->assertEqualsCanonicalizing(['key', 'label', 'value', 'unit', 'observed_at', 'stale_after'], array_keys($metric), $metric['key']);
        }
    }

    public function test_the_report_holds_the_standard_checks_and_every_group_key_on_staging(): void
    {
        $keys = array_keys($this->checks());

        foreach ([...self::STANDARD_KEYS, ...self::WEATHER_KEYS, 'ingest_ics_age', 'sources_failing', 'exposure_invite_gate', 'mail_failures', 'data_compression_enabled'] as $key) {
            $this->assertContains($key, $keys);
        }
        $this->assertNotContains('events_upcoming_published', $keys, 'On staging this is a metric, not a check.');
        $this->assertNotContains('queue_failed', $keys);
        $this->assertNotContains('queue_pending', $keys);
        $this->assertSame($keys, array_values(array_unique($keys)), 'A key is reported once.');
    }

    public function test_on_production_upcoming_events_is_a_check_and_the_gate_check_is_inactive(): void
    {
        config(['services.status_health.environment' => 'production']);

        $checks = $this->checks();

        $this->assertArrayHasKey('events_upcoming_published', $checks);
        $this->assertSame('inactive', $checks['exposure_invite_gate']['status']);
        $this->assertNotContains('events_upcoming_published', collect($this->report()->json('metrics'))->pluck('key')->all());
    }

    public function test_no_check_fails_to_run_or_goes_unreported(): void
    {
        foreach ($this->checks() as $key => $check) {
            $this->assertNotSame('Check failed to run', $check['message'], $key);
            $this->assertNotSame('Check did not report', $check['message'], $key);
        }
    }

    public function test_the_report_leaks_no_paths_secrets_or_tokens(): void
    {
        $body = $this->report()->getContent();

        $this->assertStringNotContainsString(base_path(), $body);
        $this->assertStringNotContainsString(storage_path(), $body);
        $this->assertStringNotContainsString($this->statusDir, $body);
        $this->assertStringNotContainsString(self::TOKEN, $body);
        $this->assertStringNotContainsString(hash('sha256', self::TOKEN), $body);
        $this->assertStringNotContainsString((string) config('app.key'), $body);
    }

    public function test_the_stack_reports_versions_drivers_and_packages_with_their_flags(): void
    {
        $stack = $this->report()->json('stack');

        $this->assertSame('laravel', $stack['platform']);
        $this->assertSame(app()->version(), $stack['platform_version']);
        $this->assertSame('8.4', $stack['php']['expected']);
        $this->assertSame('weather', $stack['database']['connections'][0]['name']);
        $this->assertArrayHasKey('timezone', $stack['laravel']);
        $this->assertContains('cache', array_keys($stack['laravel']['drivers']));
        $this->assertNotEmpty($stack['packages']);
        $this->assertSame(['name', 'version', 'dev'], array_keys($stack['packages'][0]));
        $this->assertNotEmpty($stack['frontend_packages']);
        $this->assertSame(['name', 'version', 'dev', 'optional'], array_keys($stack['frontend_packages'][0]));

        // The build tools are devDependencies now (logs/2026 1018 1815), so the flag means "does not ship to visitors".
        $byName = collect($stack['frontend_packages'])->keyBy('name');
        $this->assertTrue($byName['vite']['dev']);
        $this->assertTrue($byName['typescript']['dev']);
        $this->assertFalse($byName['react']['dev']);
        $this->assertFalse($byName['chart.js']['dev']);
    }

    // --- switching groups off -------------------------------------------------------------------------------------------

    public function test_a_disabled_group_reports_every_key_inactive_and_no_metrics(): void
    {
        config(['services.status_health.disabled_groups' => 'weather']);

        $response = $this->report();
        $checks = collect($response->json('checks'))->keyBy('key');

        foreach (self::WEATHER_KEYS as $key) {
            $this->assertSame('inactive', $checks[$key]['status'], $key);
            $this->assertSame('Disabled on this copy', $checks[$key]['message'], $key);
        }
        $this->assertNotContains('open_meteo_calls_today', collect($response->json('metrics'))->pluck('key')->all());
        // Other groups are untouched.
        $this->assertNotSame('Disabled on this copy', $checks['ingest_ics_age']['message']);
        $this->assertNotSame('Disabled on this copy', $checks['sources_failing']['message']);
    }

    public function test_the_weather_metric_is_reported_when_the_group_is_on(): void
    {
        $metrics = collect($this->report()->json('metrics'))->keyBy('key');

        $this->assertArrayHasKey('open_meteo_calls_today', $metrics);
        $this->assertSame('calls', $metrics['open_meteo_calls_today']['unit']);
    }

    // --- the feed checks (job status files) -----------------------------------------------------------------------------

    public function test_a_missing_job_status_file_is_unknown(): void
    {
        $check = $this->checks()['job_earthquakes'];

        $this->assertSame('unknown', $check['status']);
        $this->assertSame('Job status file missing or unreadable', $check['message']);
    }

    public function test_an_unset_status_folder_is_unknown(): void
    {
        config(['services.status_health.status_dir' => null]);

        $this->assertSame('Job status folder not configured', $this->checks()['job_snow']['message']);
    }

    public function test_job_status_files_drive_the_feed_checks(): void
    {
        $hour = 3600;
        $this->writeJobStatus('earthquakes', ['last_run_at' => self::ago(600), 'last_status' => 'success', 'last_success_at' => self::ago(600), 'consecutive_failures' => 0, 'interval_seconds' => $hour]);
        $this->writeJobStatus('groundwater', ['last_run_at' => self::ago(600), 'last_status' => 'failure', 'last_success_at' => self::ago(2 * 86400 - 600), 'consecutive_failures' => 1, 'interval_seconds' => 86400]);
        $this->writeJobStatus('snow', ['last_run_at' => self::ago(600), 'last_status' => 'failure', 'last_success_at' => self::ago(600 + 86400), 'consecutive_failures' => 3, 'interval_seconds' => 86400]);
        $this->writeJobStatus('drought', ['last_run_at' => self::ago(3 * 86400), 'last_status' => 'success', 'last_success_at' => self::ago(3 * 86400), 'consecutive_failures' => 0, 'interval_seconds' => 86400]);

        $checks = $this->checks();

        $this->assertSame('healthy', $checks['job_earthquakes']['status']);
        $this->assertNull($checks['job_earthquakes']['message']);
        $this->assertSame('hours', $checks['job_earthquakes']['unit']);

        $this->assertSame('warning', $checks['job_groundwater']['status']);
        $this->assertSame('Last run failed', $checks['job_groundwater']['message']);

        $this->assertSame('critical', $checks['job_snow']['status']);
        $this->assertSame('3 failed runs in a row', $checks['job_snow']['message']);

        // Three days since success on a daily job is 3 times the interval: past the warning (2x), short of critical (4x).
        $this->assertSame('warning', $checks['job_drought']['status']);
    }

    public function test_a_job_that_never_succeeded_or_went_silent_for_four_intervals_is_critical(): void
    {
        $this->writeJobStatus('earthquakes', ['last_run_at' => self::ago(5 * 3600), 'last_status' => 'success', 'last_success_at' => self::ago(5 * 3600), 'consecutive_failures' => 0, 'interval_seconds' => 3600]);
        $this->writeJobStatus('snow', ['last_run_at' => self::ago(60), 'last_status' => 'failure', 'last_success_at' => null, 'consecutive_failures' => 1, 'interval_seconds' => 86400]);

        $checks = $this->checks();

        $this->assertSame('critical', $checks['job_earthquakes']['status']);
        $this->assertSame('critical', $checks['job_snow']['status']);
        $this->assertSame('Never succeeded', $checks['job_snow']['message']);
    }

    // --- the standard checks that depend on this app --------------------------------------------------------------------

    public function test_the_scheduler_check_follows_the_marker(): void
    {
        $this->assertSame('critical', $this->checks()['scheduler']['status']);

        StatusHealthController::recordSchedulerRun();
        $this->assertSame('healthy', $this->checks()['scheduler']['status']);

        file_put_contents(StatusHealthController::schedulerMarkerPath(), (string) (time() - 600));
        $this->assertSame('warning', $this->checks()['scheduler']['status']);

        file_put_contents(StatusHealthController::schedulerMarkerPath(), (string) (time() - 1200));
        $this->assertSame('critical', $this->checks()['scheduler']['status']);
    }

    public function test_the_scheduler_has_the_marker_task_and_the_heartbeat_on_ingest(): void
    {
        $this->artisan('list');   // loads routes/console.php, which defines the schedule
        $events = collect(app(Schedule::class)->events());

        $marker = $events->first(fn ($e) => $e->description === 'status-health-scheduler');
        $this->assertNotNull($marker);
        $this->assertSame('* * * * *', $marker->expression);

        $ingest = $events->first(fn ($e) => str_contains($e->command ?? '', 'ingest:ics'));
        $this->assertNotNull($ingest);
        $this->assertSame('*/30 * * * *', $ingest->expression);
        $this->assertTrue($ingest->withoutOverlapping);
        $after = (new \ReflectionProperty($ingest, 'afterCallbacks'))->getValue($ingest);
        $this->assertGreaterThanOrEqual(2, count($after), 'the success and failure heartbeats');
    }

    public function test_the_heartbeat_pings_the_configured_address_and_the_failure_address(): void
    {
        config(['services.status_health.heartbeats.ingest_ics' => 'https://hc-ping.test/abc-123']);
        Http::fake(['*' => Http::response('OK')]);

        StatusHealthController::heartbeat('ingest_ics')();
        StatusHealthController::heartbeat('ingest_ics', failed: true)();

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'https://hc-ping.test/abc-123' && $request->method() === 'GET');
        Http::assertSent(fn ($request) => $request->url() === 'https://hc-ping.test/abc-123/fail');
    }

    public function test_the_heartbeat_sends_nothing_without_an_address(): void
    {
        Http::fake();

        StatusHealthController::heartbeat('ingest_ics')();
        StatusHealthController::heartbeat('ingest_ics', failed: true)();
        StatusHealthController::heartbeat('a_job_with_no_entry')();

        Http::assertNothingSent();
    }

    public function test_the_heartbeat_never_throws_when_the_ping_service_is_down(): void
    {
        config(['services.status_health.heartbeats.ingest_ics' => 'https://hc-ping.test/abc-123']);
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        StatusHealthController::heartbeat('ingest_ics')();
        StatusHealthController::heartbeat('ingest_ics', failed: true)();

        $this->addToAssertionCount(1);   // reaching this line is the assertion: nothing was thrown
    }

    public function test_debug_mode_is_critical_in_any_environment_but_local_and_testing(): void
    {
        config(['app.debug' => true]);
        $this->assertSame('healthy', $this->checks()['debug_mode']['status'], 'The test environment itself may debug.');

        $this->app['env'] = 'staging';
        $this->assertSame('critical', $this->checks()['debug_mode']['status']);

        config(['app.debug' => false]);
        $this->assertSame('healthy', $this->checks()['debug_mode']['status']);
    }

    public function test_the_php_version_check_compares_major_and_minor(): void
    {
        $running = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

        config(['services.status_health.php_version' => $running]);
        $this->assertSame('healthy', $this->checks()['php_version_expected']['status']);

        config(['services.status_health.php_version' => '7.4']);
        $this->assertSame('critical', $this->checks()['php_version_expected']['status']);
    }

    public function test_the_environment_check_warns_when_app_env_differs_from_the_reported_one(): void
    {
        config(['services.status_health.environment' => 'testing']);
        $this->assertSame('healthy', $this->checks()['app_env']['status']);

        config(['services.status_health.environment' => 'staging']);
        $this->assertSame('warning', $this->checks()['app_env']['status'], 'APP_ENV is "testing" here and the copy reports "staging".');
    }

    public function test_the_ingest_age_check_is_reported_from_the_sources_table(): void
    {
        Source::create(['name' => 'Feed', 'source_type' => 'ics', 'collector_type' => 'ics', 'active' => true, 'last_success_at' => now()->subMinutes(10)]);

        $this->assertSame('healthy', $this->checks()['ingest_ics_age']['status']);
    }
}
