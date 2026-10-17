<?php

namespace App\Console\Commands;

use App\Http\Controllers\Data\QueryController;
use App\Http\Controllers\Data\SummaryController;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Compares this app's data endpoints with the legacy API, request by request, on real data (port phase 7). Read only: it sends
 * GET requests to the legacy API and runs the app's own controllers against the weather database; it writes nothing and
 * holds no secrets. For each of the request shapes the ported pages make it prints equal or different, the time each side took, the
 * size of the answer and the peak memory this process used. Rows with equal dates may come in another order (the legacy order among
 * them was the database's); everything else, types included, must agree. Run it where the weather connection is configured
 * (staging), before switching the pages and again before the legacy API is shut down.
 */
class WeatherCompare extends Command
{
    protected $signature = 'weather:compare
        {--legacy=https://data.mojaverivervalley.org/v1 : The legacy API to compare with}
        {--only= : Only requests whose label contains this text}
        {--bulk : Include the 34 MB request for all named cities (one request to each side; needs about 1 GB of memory)}';

    protected $description = 'Compare the /data/v1 endpoints with the legacy API on real data (read only)';

    public function handle(): int
    {
        $legacy = rtrim((string) $this->option('legacy'), '/');
        $only = (string) $this->option('only');
        $db = DB::connection('weather');

        $named = array_map(fn ($c) => $c->id, $db->select("SELECT id FROM cities WHERE place_type = 'city' ORDER BY id"));
        $well = $db->selectOne('SELECT site_id FROM groundwater_wells ORDER BY site_id LIMIT 1')->site_id ?? '';
        $today = date('Y-m-d');
        $requests = [
            'cities' => 'query.php?table=cities&limit=2000',
            'weather, one city, all columns' => 'query.php?table=weather_daily&city_id=1&limit=20000',
            'weather, three columns from 2017' => 'query.php?table=weather_daily&city_id=1&columns=temp_max_f,temp_min_f,precipitation_in&from=2017-01-01&limit=20000',
            'air quality' => 'query.php?table=air_quality_daily&city_id=1&limit=5000',
            'live conditions' => 'query.php?table=live_conditions_log&city_id=1&from='.date('Y-m-d', strtotime('-2 days')).'&limit=500',
            'earthquakes, 30 days' => 'query.php?table=earthquakes&from='.date('Y-m-d', strtotime('-30 days')).'&to='.$today.'&limit=20000',
            'drought' => 'query.php?table=drought_weekly&from=2000-01-01&limit=5000',
            'groundwater, one well' => 'query.php?table=groundwater_levels&site_id='.urlencode($well).'&columns=depth_ft,qualifiers,approval,method&limit=5000',
            'stations' => 'query.php?table=stations&limit=500',
            'farmers markets' => 'query.php?table=farmers_markets&limit=500',
            'groundwater summary' => 'groundwater_summary.php',
            'climate summary, city 1' => 'climate_summary.php?city_id=1',
            'climate summary, city 7' => 'climate_summary.php?city_id=7',
            'snow summary' => 'snow_summary.php?from_year=2017',
            'error, unknown table' => 'query.php?table=nope',
            'error, unknown column' => 'query.php?table=cities&columns=name,secret',
        ];
        if ($this->option('bulk')) {
            ini_set('memory_limit', '1536M');
            $requests['weather, bulk, all named cities'] = 'query.php?table=weather_daily&columns=city_id,date,temp_max_f,temp_min_f,feels_max_f,feels_min_f,precipitation_in,wind_gust_max_mph,weather_condition,updated_at&city_id='.implode(',', $named).'&limit=500000';
        }

        $rows = [];
        $failed = 0;
        foreach ($requests as $label => $address) {
            if ($only !== '' && ! str_contains($label, $only)) {
                continue;
            }
            $result = $this->compareOne($legacy, $address);
            $failed += $result['equal'] ? 0 : 1;
            $rows[] = [$label, $result['equal'] ? 'equal' : 'DIFFERENT', $result['legacyMs'], $result['ownMs'], $result['bytes'], $result['peakMb'], $result['note']];
            $this->line(sprintf('%-34s %-9s legacy %6d ms   here %6d ms   %9s bytes   peak %5.1f MB   %s', $label, $result['equal'] ? 'equal' : 'DIFFERENT', $result['legacyMs'], $result['ownMs'], number_format($result['bytes']), $result['peakMb'], $result['note']));
        }
        $this->newLine();
        $this->line($failed === 0 ? 'ALL EQUAL' : "$failed request(s) differ. A request that reads data changing by the minute (live conditions, earthquakes) can differ between the two reads; run it again before treating it as real.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{equal: bool, legacyMs: int, ownMs: int, bytes: int, peakMb: float, note: string} */
    private function compareOne(string $legacy, string $address): array
    {
        $start = hrtime(true);
        try {
            $theirs = Http::timeout(600)->withHeaders(['Accept-Encoding' => 'gzip'])->get($legacy.'/'.$address);
            $theirBody = $theirs->body();
            $theirStatus = $theirs->status();
        } catch (Throwable $e) {
            return ['equal' => false, 'legacyMs' => 0, 'ownMs' => 0, 'bytes' => 0, 'peakMb' => 0.0, 'note' => 'legacy request failed: '.$e->getMessage()];
        }
        $legacyMs = (int) ((hrtime(true) - $start) / 1e6);

        $start = hrtime(true);
        memory_reset_peak_usage();
        try {
            [$ownStatus, $ownBody] = $this->ownAnswer($address);
        } catch (Throwable $e) {
            return ['equal' => false, 'legacyMs' => $legacyMs, 'ownMs' => 0, 'bytes' => strlen($theirBody), 'peakMb' => 0.0, 'note' => 'own request failed: '.$e->getMessage()];
        }
        $ownMs = (int) ((hrtime(true) - $start) / 1e6);
        $peakMb = memory_get_peak_usage(true) / 1048576;

        $note = '';
        $equal = $ownStatus === $theirStatus;
        if (! $equal) {
            $note = "status $ownStatus here, $theirStatus there";
        } else {
            $a = json_decode($theirBody, true);
            $b = json_decode($ownBody, true);
            if ($a === null || $b === null) {
                $equal = false;
                $note = 'an answer is not valid JSON';
            } else {
                $note = self::difference(self::canonical($a), self::canonical($b), '') ?? '';
                foreach (['legacy' => $a, 'here' => $b] as $side => $decoded) {
                    if (($column = self::unordered($decoded)) !== null) {
                        $note = trim($note.' '.$side.': not in order of '.$column);
                    }
                }
                $equal = $note === '';
            }
        }

        return ['equal' => $equal, 'legacyMs' => $legacyMs, 'ownMs' => $ownMs, 'bytes' => strlen($ownBody), 'peakMb' => $peakMb, 'note' => $note];
    }

    /** The app's own answer to an address, without going through the web middleware (the gate, sessions). @return array{0: int, 1: string} */
    private function ownAnswer(string $address): array
    {
        $request = Request::create('/data/v1/'.$address, 'GET');
        $path = parse_url($address, PHP_URL_PATH);
        $response = match ($path) {
            'query.php' => app(QueryController::class)($request),
            'climate_summary.php' => app(SummaryController::class)->climate($request),
            'groundwater_summary.php' => app(SummaryController::class)->groundwater($request),
            'snow_summary.php' => app(SummaryController::class)->snow($request),
            default => throw new \InvalidArgumentException("no endpoint for $path"),
        };

        if ($response instanceof StreamedResponse) {
            ob_start();
            $response->sendContent();
            $body = (string) ob_get_clean();
        } else {
            $body = (string) $response->getContent();
        }

        return [$response->getStatusCode(), $body];
    }

    /** The date column a list of rows should be ascending in but is not, or null. */
    private static function unordered(mixed $value): ?string
    {
        if (! is_array($value) || ! array_is_list($value) || ! $value || ! is_array($value[0])) {
            return null;
        }
        foreach (['date', 'reading_date', 'event_time', 'observed_at'] as $column) {
            if (array_key_exists($column, $value[0])) {
                $previous = null;
                foreach ($value as $row) {
                    if ($previous !== null && $row[$column] < $previous) {
                        return $column;
                    }
                    $previous = $row[$column];
                }

                return null;
            }
        }

        return null;
    }

    /** A list of rows is put in a fixed order (by every value) so equal-date rows in another order do not count as a difference. */
    private static function canonical(mixed $value): mixed
    {
        if (is_array($value) && array_is_list($value) && $value && is_array($value[0]) && ! array_is_list($value[0]) && isset($value[0]['id'])) {
            usort($value, fn ($x, $y) => ($x['id'] ?? 0) <=> ($y['id'] ?? 0));
        } elseif (is_array($value) && array_is_list($value) && $value && is_array($value[0]) && ! array_is_list($value[0]) && count($value) > 1) {
            usort($value, fn ($x, $y) => strcmp(json_encode($x), json_encode($y)));
        }

        return $value;
    }

    /** The path and values where two decoded answers first differ, types included; null when identical. */
    public static function difference(mixed $expected, mixed $actual, string $path): ?string
    {
        if (is_array($expected) && is_array($actual)) {
            if (array_keys($expected) !== array_keys($actual)) {
                return $path.': keys differ ('.count($expected).' against '.count($actual).')';
            }
            foreach ($expected as $k => $v) {
                if ($found = self::difference($v, $actual[$k], $path.'/'.$k)) {
                    return $found;
                }
            }

            return null;
        }

        return $expected === $actual ? null : $path.': legacy '.json_encode($expected).', here '.json_encode($actual);
    }
}
