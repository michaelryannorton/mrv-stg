<?php

namespace Tests\Feature\StatusHealth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\WeatherDatabase;
use Tests\TestCase;

/**
 * Shared setup for the status dashboard's health endpoint tests: the stand-in weather database (plus the tables the weather
 * checks read that the data endpoints do not), a throwaway job status folder, and the endpoint's configuration for one token.
 */
abstract class StatusHealthTestCase extends TestCase
{
    use RefreshDatabase;
    use WeatherDatabase;

    /** A made-up token. Its hash is what the tests put in config; the raw token never exists outside the tests. */
    protected const TOKEN = 'ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12cd34ef56abcd';

    protected string $statusDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useWeatherDatabase();

        $weather = DB::connection('weather');
        // The data endpoints' stand-in has no completed_at on this table, and no budget tables.
        $weather->statement('DROP TABLE "climate_fetch_progress"');
        $weather->statement('CREATE TABLE "climate_fetch_progress" ("snapshot", "city_id", "model", "completed_at")');
        $weather->statement('CREATE TABLE "backfill_budget" ("budget_date", "equivalent_calls_spent")');
        $weather->statement('CREATE TABLE "airquality_budget" ("budget_date", "equivalent_calls_spent")');

        $this->statusDir = sys_get_temp_dir().'/status-health-test-'.bin2hex(random_bytes(4));
        mkdir($this->statusDir);
        $this->clearEndpointFiles();

        config([
            'services.status_health.token_hash' => hash('sha256', self::TOKEN),
            'services.status_health.token_hash_next' => null,
            'services.status_health.property' => 'mojave-river-valley',
            'services.status_health.environment' => 'staging',
            'services.status_health.php_version' => '8.4',
            'services.status_health.database' => true,
            'services.status_health.connections' => 'weather',
            'services.status_health.scheduler' => true,
            'services.status_health.queue' => false,
            'services.status_health.disabled_groups' => '',
            'services.status_health.status_dir' => $this->statusDir,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->statusDir);
        $this->clearEndpointFiles();
        parent::tearDown();
    }

    /** The endpoint caches slow checks and keeps the scheduler marker under storage/framework; start every test without them. */
    private function clearEndpointFiles(): void
    {
        File::deleteDirectory(storage_path('framework/status-health-cache'));
        File::delete(storage_path('framework/status-health-scheduler'));
    }

    protected function report(array $headers = ['Authorization' => 'Bearer '.self::TOKEN])
    {
        return $this->withHeaders($headers)->getJson('/_status/health');
    }

    /** The report's checks keyed by their key. */
    protected function checks(array $headers = ['Authorization' => 'Bearer '.self::TOKEN]): array
    {
        $response = $this->report($headers);
        $response->assertOk();

        return collect($response->json('checks'))->keyBy('key')->all();
    }

    protected function writeJobStatus(string $job, array $data): void
    {
        file_put_contents("{$this->statusDir}/{$job}.json", json_encode(['job' => $job] + $data));
    }

    protected static function ago(int $seconds): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', time() - $seconds);
    }
}
