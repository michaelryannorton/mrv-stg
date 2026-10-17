<?php

namespace Tests\Feature\Data;

use App\Models\User;
use App\Support\Weather\WeatherTables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\WeatherDatabase;
use Tests\TestCase;

/**
 * The data endpoints against the answers the legacy API gave (port phase 7). Each saved production response is replayed: its rows
 * are loaded into the stand-in weather database (shuffled, with decoy rows the request's filters must leave out), the same request
 * is made to /data/v1, and the decoded answer must be the same, key for key and type for type. Rows with equal dates may come
 * out in another order (the legacy order among them was the database's); everything else, including the date order, must agree.
 */
class WeatherEndpointsTest extends TestCase
{
    use RefreshDatabase;
    use WeatherDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useWeatherDatabase();
    }

    private function body($response): string
    {
        return $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : $response->getContent();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function queryCases(): array
    {
        $cases = json_decode(gzdecode(file_get_contents(__DIR__.'/../../Fixtures/weather/cases.json.gz')), true);
        $out = [];
        foreach ($cases as $case) {
            if ($case['kind'] === 'query') {
                $out[$case['name']] = [$case];
            }
        }

        return $out;
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function summaryCases(): array
    {
        $cases = json_decode(gzdecode(file_get_contents(__DIR__.'/../../Fixtures/weather/cases.json.gz')), true);
        $out = [];
        foreach ($cases as $case) {
            if ($case['kind'] === 'summary') {
                $out[$case['name']] = [$case];
            }
        }

        return $out;
    }

    private function dateColumnOf(string $table): ?string
    {
        $cols = WeatherTables::columns($table);
        foreach (['date', 'reading_date', 'event_time', 'observed_at'] as $c) {
            if (in_array($c, $cols, true)) {
                return $c;
            }
        }

        return null;
    }

    /** Rows that every filter in the request must exclude, shaped like the expected rows. */
    private function decoys(array $rows, array $query, ?string $dateColumn): array
    {
        if (! $rows) {
            return [];
        }
        $decoys = [];
        $sample = $rows[0];
        if (isset($query['city_id']) && array_key_exists('city_id', $sample)) {
            $decoys[] = array_merge($sample, ['city_id' => 999]);
        }
        foreach (['site_id', 'station_id', 'area_fips'] as $key) {
            if (isset($query[$key]) && array_key_exists($key, $sample)) {
                $decoys[] = array_merge($sample, [$key => 'other-'.$key]);
            }
        }
        if ($dateColumn && array_key_exists($dateColumn, $sample)) {
            if (isset($query['from'])) {
                $decoys[] = array_merge($sample, [$dateColumn => '1900-01-01']);
            }
            if (isset($query['to'])) {
                $decoys[] = array_merge($sample, [$dateColumn => '2999-12-31 23:59:59']);
            }
        }

        return $decoys;
    }

    private function canonical(array $rows, ?string $dateColumn): array
    {
        usort($rows, fn ($a, $b) => [$dateColumn ? ($a[$dateColumn] ?? '') : '', json_encode($a)] <=> [$dateColumn ? ($b[$dateColumn] ?? '') : '', json_encode($b)]);

        return $rows;
    }

    #[DataProvider('queryCases')]
    public function test_query_endpoint_answers_as_the_legacy_api_did(array $case): void
    {
        parse_str((string) parse_url($case['address'], PHP_URL_QUERY), $query);

        if ($case['status'] !== 200) {
            $this->getJson($case['address'])->assertStatus($case['status'])->assertExactJson($case['body']);

            return;
        }

        $table = $query['table'];
        $dateColumn = $this->dateColumnOf($table);
        $expected = $case['body'];
        // A projection may leave out the column a filter works on (the key filters are not identity columns): the stored row has it.
        $stored = array_map(function ($row) use ($query, $table) {
            foreach (['site_id', 'station_id', 'area_fips'] as $key) {
                if (isset($query[$key]) && in_array($key, WeatherTables::columns($table), true)) {
                    $row = array_merge([$key => $query[$key]], $row);
                }
            }

            return $row;
        }, $expected);
        $rows = array_merge($stored, $this->decoys($stored, $query, $dateColumn));
        mt_srand(7);
        shuffle($rows);
        // Rows saved with a column projection lack the others: the stand-in keeps those as NULL, which the projection leaves out.
        $this->insertWeatherRows($table, $rows);

        $response = $this->get($case['address'])->assertOk()->assertHeader('Content-Type', 'application/json');
        $actual = json_decode($this->body($response), true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(count($expected), $actual, $case['name']);
        if ($dateColumn && isset($actual[0][$dateColumn])) {
            $dates = array_column($actual, $dateColumn);
            $sorted = $dates;
            sort($sorted);
            $this->assertSame($sorted, $dates, $case['name'].': ordered by '.$dateColumn);
        }
        $this->assertRowsSame($this->canonical($expected, $dateColumn), $this->canonical($actual, $dateColumn), $case['name']);
    }

    /** Row by row, so a difference is reported as the first differing pair of rows and not as a diff of thousands of lines. */
    private function assertRowsSame(array $expected, array $actual, string $label): void
    {
        $this->assertCount(count($expected), $actual, $label);
        foreach ($expected as $i => $row) {
            if ($row !== $actual[$i]) {
                $this->fail($label.' row '.$i."\n expected ".json_encode($row)."\n actual   ".json_encode($actual[$i]));
            }
        }
        $this->addToAssertionCount(1);
    }

    #[DataProvider('summaryCases')]
    public function test_summary_endpoints_answer_as_the_legacy_api_did(array $case): void
    {
        $this->loadRawFor($case['name']);

        $response = $this->get($case['address'])->assertOk();
        $actual = json_decode($this->body($response), true, 512, JSON_THROW_ON_ERROR);

        $this->assertNull($this->firstDifference($case['body'], $actual, ''), $case['name']);
        $this->assertSame($case['body'], $actual, $case['name']);
    }

    /** The path and the two values where two decoded answers first differ (null when they are identical, types included). */
    private function firstDifference(mixed $expected, mixed $actual, string $path): ?string
    {
        if (is_array($expected) && is_array($actual)) {
            if (array_keys($expected) !== array_keys($actual)) {
                return $path.': keys '.json_encode(array_keys($expected)).' against '.json_encode(array_keys($actual));
            }
            foreach ($expected as $k => $v) {
                if ($found = $this->firstDifference($v, $actual[$k], $path.'/'.$k)) {
                    return $found;
                }
            }

            return null;
        }

        return $expected === $actual ? null : $path.': expected '.var_export($expected, true).' actual '.var_export($actual, true);
    }

    /** Loads only the raw rows the named summary is computed from. */
    private function loadRawFor(string $name): void
    {
        $raw = $this->weatherFixture('raw.json.gz');
        $tables = match (true) {
            str_contains($name, 'groundwater') => ['groundwater_wells', 'groundwater_levels'],
            str_contains($name, 'snow') => ['snow_observations'],
            default => ['climate_monthly', 'climate_snapshots'],
        };
        $numeric = [
            'groundwater_levels' => ['depth_ft' => 'REAL'],
            'snow_observations' => ['precip_in' => 'REAL', 'snowfall_in' => 'REAL', 'snow_depth_in' => 'REAL'],
            'climate_monthly' => ['tmax_mean_f' => 'REAL', 'tmin_mean_f' => 'REAL', 'precip_in' => 'REAL', 'days' => 'INTEGER', 'year' => 'INTEGER', 'month' => 'INTEGER'],
        ];
        foreach ($tables as $table) {
            $this->insertWeatherRows($table, $raw[$table], $numeric[$table] ?? []);
        }
        if (in_array('climate_monthly', $tables, true)) {
            // The real climate_fetch_progress is not readable through the legacy API: one row per snapshot, city and model that has monthly rows.
            $progress = [];
            foreach ($raw['climate_monthly'] as $r) {
                $progress[$r['snapshot'].'|'.$r['city_id'].'|'.$r['model']] = ['snapshot' => $r['snapshot'], 'city_id' => $r['city_id'], 'model' => $r['model']];
            }
            $this->insertWeatherRows('climate_fetch_progress', array_values($progress));
        }
    }

    public function test_unknown_summary_input_is_refused_like_the_legacy_files(): void
    {
        $this->get('/data/v1/climate_summary.php')->assertStatus(400)->assertExactJson(['error' => 'city_id is required']);
        $this->get('/data/v1/climate_summary.php?city_id=abc')->assertStatus(400);
        $this->get('/data/v1/climate_summary.php?city_id=0')->assertStatus(400);
    }

    public function test_snow_summary_clamps_the_first_year_like_the_legacy_file(): void
    {
        $this->loadRawFor('snow');
        $this->assertSame(1961, $this->get('/data/v1/snow_summary.php?from_year=1900')->json('monthly_from_year'));
        $this->assertSame((int) date('Y'), $this->get('/data/v1/snow_summary.php?from_year=2999')->json('monthly_from_year'));
        $this->assertSame(2017, $this->get('/data/v1/snow_summary.php')->json('monthly_from_year'));
    }

    // ---- the query endpoint's rules, from reading api/v1/query.php ---------------------------------------------------------------

    private function weather(array $rows): void
    {
        $this->insertWeatherRows('weather_daily', $rows);
    }

    private function rows(): array
    {
        return [
            ['id' => 1, 'city_id' => 1, 'date' => '2025-01-03', 'temp_max_f' => '60.1', 'temp_min_f' => '40.0', 'created_at' => 'c', 'updated_at' => 'u'],
            ['id' => 2, 'city_id' => 2, 'date' => '2025-01-01', 'temp_max_f' => '61.1', 'temp_min_f' => '41.0', 'created_at' => 'c', 'updated_at' => 'u'],
            ['id' => 3, 'city_id' => 1, 'date' => '2025-01-01', 'temp_max_f' => '62.1', 'temp_min_f' => '42.0', 'created_at' => 'c', 'updated_at' => 'u'],
            ['id' => 4, 'city_id' => 3, 'date' => '2025-01-02', 'temp_max_f' => '63.1', 'temp_min_f' => '43.0', 'created_at' => 'c', 'updated_at' => 'u'],
        ];
    }

    private function answer(string $address): array
    {
        return json_decode($this->body($this->get($address)->assertOk()), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_the_default_answer_has_every_column_including_id_and_audit_columns_ordered_by_date_then_key(): void
    {
        $this->weather($this->rows());
        $rows = $this->answer('/data/v1/query.php?table=weather_daily');
        $this->assertSame([3, 2, 4, 1], array_column($rows, 'id'));
        $this->assertSame(['2025-01-01', '2025-01-01', '2025-01-02', '2025-01-03'], array_column($rows, 'date'));
        $this->assertArrayHasKey('created_at', $rows[0]);
        $this->assertArrayHasKey('id', $rows[0]);
    }

    public function test_columns_are_projected_with_the_identity_columns_first_and_nothing_else(): void
    {
        $this->weather($this->rows());
        $rows = $this->answer('/data/v1/query.php?table=weather_daily&columns=temp_min_f,temp_max_f&city_id=1');
        $this->assertSame(['city_id', 'date', 'temp_min_f', 'temp_max_f'], array_keys($rows[0]));
        $this->assertCount(2, $rows);
        $this->assertSame(['city_id', 'date', 'temp_max_f', 'created_at'], array_keys($this->answer('/data/v1/query.php?table=weather_daily&columns=date,temp_max_f,created_at')[0]));
        // an empty list falls back to the whitelisted columns (no id, no audit columns)
        $this->assertArrayNotHasKey('id', $this->answer('/data/v1/query.php?table=weather_daily&columns=')[0]);
    }

    public function test_unknown_columns_are_refused_with_the_list_of_allowed_ones(): void
    {
        $response = $this->get('/data/v1/query.php?table=weather_daily&columns=temp_max_f,password')->assertStatus(400);
        $body = json_decode($response->getContent(), true);
        $this->assertSame('unknown column(s)', $body['error']);
        $this->assertSame(['password'], $body['invalid']);
        $this->assertContains('created_at', $body['allowed']);
        $this->get('/data/v1/query.php?table=weather_daily&columns[]=temp_max_f')->assertStatus(400);
    }

    public function test_city_ids_are_cast_to_integers_and_junk_is_dropped(): void
    {
        $this->weather($this->rows());
        $this->assertSame([3, 1], array_column($this->answer('/data/v1/query.php?table=weather_daily&city_id=1;DROP TABLE x'), 'id'));
        $this->assertCount(3, $this->answer('/data/v1/query.php?table=weather_daily&city_id=1,%203,0,-4,abc'));
        $this->assertCount(4, $this->answer('/data/v1/query.php?table=weather_daily&city_id=abc'));
        $this->assertCount(4, $this->answer('/data/v1/query.php?table=weather_daily&city_id[]=1'));
    }

    public function test_from_and_to_are_values_never_sql(): void
    {
        $this->weather($this->rows());
        $this->assertCount(2, $this->answer('/data/v1/query.php?table=weather_daily&from=2025-01-02'));
        $this->assertCount(3, $this->answer('/data/v1/query.php?table=weather_daily&to=2025-01-02'));
        $this->assertCount(1, $this->answer('/data/v1/query.php?table=weather_daily&from=2025-01-02&to=2025-01-02'));
        // an injection attempt is only a string to compare dates with: no error, and no row it would not select as a plain string
        $this->assertSame(['2025-01-02', '2025-01-03'], array_column($this->answer('/data/v1/query.php?table=weather_daily&from='.urlencode("2025-01-01' OR '1'='1")), 'date'));
        $this->assertSame(4, count($this->answer('/data/v1/query.php?table=weather_daily')), 'the table is intact');
    }

    public function test_the_limit_defaults_caps_and_never_goes_negative(): void
    {
        $this->weather($this->rows());
        $this->assertCount(2, $this->answer('/data/v1/query.php?table=weather_daily&limit=2'));
        $this->assertCount(0, $this->answer('/data/v1/query.php?table=weather_daily&limit=0'));
        $this->assertCount(0, $this->answer('/data/v1/query.php?table=weather_daily&limit=-5'));
        $this->assertCount(0, $this->answer('/data/v1/query.php?table=weather_daily&limit=abc'));
        $this->assertCount(0, $this->answer('/data/v1/query.php?table=weather_daily&limit='), 'an empty limit is 0, as in the legacy file');
        $this->assertCount(4, $this->answer('/data/v1/query.php?table=weather_daily&limit=99999999'));
    }

    public function test_key_filters_apply_only_to_tables_that_have_the_column(): void
    {
        $this->insertWeatherRows('groundwater_levels', [
            ['id' => 1, 'site_id' => 'A', 'reading_date' => '2020-01-01', 'measured_at' => '2020-01-01 12:00:00', 'depth_ft' => '10.00'],
            ['id' => 2, 'site_id' => 'B', 'reading_date' => '2020-01-02', 'measured_at' => '2020-01-02 12:00:00', 'depth_ft' => '11.00'],
        ]);
        $this->assertCount(1, $this->answer('/data/v1/query.php?table=groundwater_levels&site_id=A'));
        $this->insertWeatherRows('cities', [['id' => 1, 'name' => 'X', 'place_type' => 'city', 'latitude' => '1', 'longitude' => '2']]);
        $this->assertCount(1, $this->answer('/data/v1/query.php?table=cities&site_id=A&from=2020-01-01'), 'a table without that column ignores the filter');
    }

    public function test_an_empty_table_answers_an_empty_array_and_unknown_tables_are_refused(): void
    {
        $this->assertSame('[]', $this->body($this->get('/data/v1/query.php?table=weather_daily')->assertOk()));
        $response = $this->get('/data/v1/query.php')->assertStatus(400);
        $this->assertSame('unknown table', json_decode($response->getContent(), true)['error']);
        $this->get('/data/v1/query.php?table=users')->assertStatus(400);
        $this->get('/data/v1/query.php?table[]=cities')->assertStatus(400);
    }

    public function test_the_table_whitelist_is_the_legacy_one(): void
    {
        $legacy = file_get_contents(base_path('../api/inc/tables.php'));
        if ($legacy === false) {
            $this->markTestSkipped('The legacy folder is not next to this app.');
        }
        foreach (WeatherTables::SCHEMAS as $table => $schema) {
            $this->assertStringContainsString("'$table' => [", $legacy);
            foreach ($schema['columns'] as $column) {
                $this->assertStringContainsString("'$column'", $legacy, "$table.$column");
            }
        }
        $this->assertCount(17, WeatherTables::SCHEMAS);
    }

    // ---- who can ask, and how --------------------------------------------------------------------------------------------------

    public function test_only_get_requests_reach_the_data_routes_and_all_of_them_are_throttled(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'data/v1/'));
        $this->assertCount(4, $routes);
        foreach ($routes as $route) {
            $this->assertSame(['GET', 'HEAD'], $route->methods());
            $this->assertContains('throttle:weather-data', $route->gatherMiddleware());
        }
        $this->post('/data/v1/query.php?table=cities')->assertStatus(405);
    }

    public function test_the_data_follows_the_invite_only_gate(): void
    {
        config(['app.invite_only' => true]);
        $this->get('/data/v1/query.php?table=cities')->assertRedirect('/login');
        $this->get('/data/v1/groundwater_summary.php')->assertRedirect('/login');

        $this->actingAs(User::factory()->create());
        $this->get('/data/v1/query.php?table=cities')->assertOk();
    }
}
