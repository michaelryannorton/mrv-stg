<?php

namespace App\Http\Controllers\Data;

use App\Http\Controllers\Controller;
use App\Services\Weather\ClimateSummary;
use App\Services\Weather\GroundwaterSummary;
use App\Services\Weather\SnowSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The legacy API's three pre-aggregated endpoints (api/v1/climate_summary.php, groundwater_summary.php, snow_summary.php),
 * served by the app itself (port phase 7). Read only; the aggregations are in App\Services\Weather.
 */
class SummaryController extends Controller
{
    private const SNOW_STATION = 'USC00040741'; // Big Bear Lake, CA

    private const HEADERS = ['Cache-Control' => 'max-age=3600, private'];

    public function climate(Request $request): JsonResponse
    {
        $cityId = (int) $request->query('city_id', 0);
        if ($cityId < 1) {
            return response()->json(['error' => 'city_id is required'], 400);
        }

        return response()->json((new ClimateSummary(DB::connection('weather')))->forCity($cityId), 200, self::HEADERS);
    }

    public function groundwater(): JsonResponse
    {
        return response()->json((new GroundwaterSummary(DB::connection('weather')))->all(), 200, self::HEADERS);
    }

    public function snow(Request $request): JsonResponse
    {
        $fromYear = (int) $request->query('from_year', 2017);
        $fromYear = max(1961, min($fromYear, (int) date('Y')));

        return response()->json((new SnowSummary(DB::connection('weather')))->forStation(self::SNOW_STATION, $fromYear), 200, self::HEADERS);
    }
}
