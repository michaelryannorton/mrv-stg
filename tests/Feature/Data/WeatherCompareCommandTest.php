<?php

namespace Tests\Feature\Data;

use Illuminate\Support\Facades\Http;
use Tests\Support\WeatherDatabase;
use Tests\TestCase;

class WeatherCompareCommandTest extends TestCase
{
    use WeatherDatabase;

    private function stations(): array
    {
        foreach ($this->weatherFixture('cases.json.gz') as $case) {
            if ($case['name'] === 'stations') {
                return $case['body'];
            }
        }
        $this->fail('no stations fixture');
    }

    public function test_it_reports_equal_when_the_legacy_answer_is_the_same(): void
    {
        $this->useWeatherDatabase();
        $stations = $this->stations();
        $this->insertWeatherRows('stations', $stations);
        Http::fake(['legacy.test/*' => Http::response(array_reverse($stations))]); // order among rows is not a difference

        $this->artisan('weather:compare', ['--legacy' => 'https://legacy.test/v1', '--only' => 'stations'])
            ->expectsOutputToContain('equal')
            ->expectsOutputToContain('ALL EQUAL')
            ->assertExitCode(0);
    }

    public function test_it_reports_the_first_difference_and_fails(): void
    {
        $this->useWeatherDatabase();
        $stations = $this->stations();
        $this->insertWeatherRows('stations', $stations);
        $changed = $stations;
        $changed[3]['elevation_ft'] = '1';
        Http::fake(['legacy.test/*' => Http::response($changed)]);

        $this->artisan('weather:compare', ['--legacy' => 'https://legacy.test/v1', '--only' => 'stations'])
            ->expectsOutputToContain('DIFFERENT')
            ->assertExitCode(1);
    }

    public function test_it_reports_a_legacy_failure_as_a_difference(): void
    {
        $this->useWeatherDatabase();
        Http::fake(['legacy.test/*' => Http::response('no', 500)]);

        $this->artisan('weather:compare', ['--legacy' => 'https://legacy.test/v1', '--only' => 'stations'])->assertExitCode(1);
    }
}
