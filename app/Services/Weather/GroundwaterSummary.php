<?php

namespace App\Services\Weather;

use App\Support\Weather\LegacyRound;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Groundwater summary for every tracked well (port of api/inc/groundwater_summary.php, D7): the latest depth to water and how it
 * has changed over about ten years. DEPTH IS BELOW LAND SURFACE, so a bigger depth is a LOWER water table; `change_ft` is latest
 * depth minus the depth about ten years earlier (positive: the water got deeper). Only readings taken under ordinary static
 * conditions are used (qualifier empty or exactly "Static", and a depth present). The queries and the arithmetic are the legacy ones.
 */
class GroundwaterSummary
{
    private const COMPARE_YEARS = 10;

    private const COMPARE_TOLERANCE_DAYS = 548; // the earlier reading may be up to 18 months off

    private const STALE_YEARS = 3; // a latest reading older than this is not "now"

    private const STEADY_FT = 3.0; // changes within this are called steady (seasonal and measurement noise)

    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * Which side of Cajon Pass a well is on, from USGS's own codes: wells over the California Coastal basin-fill aquifer or in the
     * Santa Ana River hydrologic unit (HUC 180702...) belong to the San Bernardino Valley; everything else tracked is in the desert basins.
     */
    public static function area(?string $aquifer, ?string $huc): string
    {
        if ($aquifer === 'N100CACSTL' || ($huc !== null && str_starts_with($huc, '180702'))) {
            return 'san_bernardino_valley';
        }

        return 'high_desert';
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $wells = $this->db->select('SELECT site_id, name, latitude, longitude, altitude_ft, well_depth_ft, huc, aquifer_code FROM groundwater_wells ORDER BY site_id');
        if (! $wells) {
            return ['wells' => [], 'totals' => ['wells' => 0]];
        }

        $all = [];
        foreach ($this->db->select('SELECT site_id, COUNT(*) AS n FROM groundwater_levels GROUP BY site_id') as $r) {
            $all[$r->site_id] = (int) $r->n;
        }
        $newestRow = (array) $this->db->selectOne('SELECT MAX(reading_date) AS newest FROM groundwater_levels');
        $newest = ($newestRow['newest'] ?? null) ?: null;

        $series = [];
        foreach ($this->db->cursor("SELECT site_id, reading_date, depth_ft FROM groundwater_levels
                          WHERE depth_ft IS NOT NULL AND qualifiers IN ('', 'Static')
                          ORDER BY site_id, reading_date, measured_at") as $r) {
            $series[$r->site_id][] = [$r->reading_date, (float) $r->depth_ft];
        }

        $staleBefore = $newest ? (new DateTimeImmutable($newest))->modify('-'.self::STALE_YEARS.' years')->format('Y-m-d') : null;
        $out = [];
        $changes = [];
        $counts = ['falling' => 0, 'steady' => 0, 'rising' => 0, 'unknown' => 0];
        foreach ($wells as $w) {
            $id = $w->site_id;
            $pts = $series[$id] ?? [];
            $entry = [
                'site_id' => $id,
                'name' => $w->name,
                'latitude' => (float) $w->latitude,
                'longitude' => (float) $w->longitude,
                'altitude_ft' => $w->altitude_ft !== null ? (float) $w->altitude_ft : null,
                'well_depth_ft' => $w->well_depth_ft !== null ? (float) $w->well_depth_ft : null,
                'huc' => $w->huc,
                'aquifer_code' => $w->aquifer_code,
                'area' => self::area($w->aquifer_code, $w->huc),
                'readings_total' => $all[$id] ?? 0,
                'readings_used' => count($pts),
                'first_date' => $pts ? $pts[0][0] : null,
                'last_date' => null, 'last_depth_ft' => null,
                'compare_date' => null, 'compare_depth_ft' => null, 'change_ft' => null,
                'status' => 'unknown',
            ];
            if ($pts) {
                [$lastDate, $lastDepth] = $pts[count($pts) - 1];
                $entry['last_date'] = $lastDate;
                $entry['last_depth_ft'] = $lastDepth;
                if ($staleBefore !== null && $lastDate >= $staleBefore) {
                    $target = (new DateTimeImmutable($lastDate))->modify('-'.self::COMPARE_YEARS.' years');
                    $best = null;
                    $bestGap = null;
                    foreach ($pts as [$d, $v]) {
                        $gap = abs((int) $target->diff(new DateTimeImmutable($d))->days);
                        if ($gap <= self::COMPARE_TOLERANCE_DAYS && ($bestGap === null || $gap < $bestGap) && $d < $lastDate) {
                            $best = [$d, $v];
                            $bestGap = $gap;
                        }
                    }
                    if ($best !== null) {
                        $change = LegacyRound::round($lastDepth - $best[1], 1);
                        $entry['compare_date'] = $best[0];
                        $entry['compare_depth_ft'] = $best[1];
                        $entry['change_ft'] = $change;
                        $entry['status'] = $change > self::STEADY_FT ? 'falling' : ($change < -self::STEADY_FT ? 'rising' : 'steady');
                        $changes[] = $change;
                    }
                }
            }
            $counts[$entry['status']]++;
            $out[] = $entry;
        }

        sort($changes);
        $n = count($changes);
        $median = $n === 0 ? null : ($n % 2 ? $changes[intdiv($n, 2)] : LegacyRound::round(($changes[$n / 2 - 1] + $changes[$n / 2]) / 2, 1));

        return [
            'wells' => $out,
            'totals' => [
                'wells' => count($out),
                'compared' => $n,
                'falling' => $counts['falling'], 'steady' => $counts['steady'], 'rising' => $counts['rising'], 'unknown' => $counts['unknown'],
                'median_change_ft' => $median,
                'readings_total' => array_sum($all),
                'newest_reading' => $newest,
                'compare_years' => self::COMPARE_YEARS,
                'steady_within_ft' => self::STEADY_FT,
            ],
        ];
    }
}
