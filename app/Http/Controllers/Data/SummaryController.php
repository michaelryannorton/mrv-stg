<?php

namespace App\Http\Controllers\Data;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Data\Concerns\CompressesJson;
use App\Services\Weather\ClimateSummary;
use App\Services\Weather\GroundwaterSummary;
use App\Services\Weather\SnowSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The legacy API's three pre-aggregated endpoints (api/v1/climate_summary.php, groundwater_summary.php, snow_summary.php),
 * served by the app itself (port phase 7). Read only; the aggregations are in App\Services\Weather.
 */
class SummaryController extends Controller
{
    use CompressesJson;

    private const SNOW_STATION = 'USC00040741'; // Big Bear Lake, CA

    private const HEADERS = ['Cache-Control' => 'max-age=3600, private'];

    public function climate(Request $request): JsonResponse|Response
    {
        $cityId = (int) $request->query('city_id', 0);
        if ($cityId < 1) {
            return response()->json(['error' => 'city_id is required'], 400);
        }

        return $this->jsonAnswer($request, (new ClimateSummary(DB::connection('weather')))->forCity($cityId), self::HEADERS);
    }

    public function groundwater(Request $request): Response
    {
        return $this->jsonAnswer($request, (new GroundwaterSummary(DB::connection('weather')))->all(), self::HEADERS);
    }

    public function snow(Request $request): Response
    {
        $fromYear = (int) $request->query('from_year', 2017);
        $fromYear = max(1961, min($fromYear, (int) date('Y')));

        return $this->jsonAnswer($request, (new SnowSummary(DB::connection('weather')))->forStation(self::SNOW_STATION, $fromYear), self::HEADERS);
    }
}
