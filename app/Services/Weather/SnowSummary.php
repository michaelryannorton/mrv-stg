<?php

namespace App\Services\Weather;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Observed snow summary for one station (port of api/inc/snow_summary.php, D9): snowfall by snow season (July 1 to June 30, named
 * for the year it starts in), record values, and average snow depth by calendar month. Values NOAA flagged as failing its checks
 * (a non-empty quality flag, the second field of each *_attr string) are left out of every total, average and record.
 * The SQL is the legacy SQL, word for word.
 */
class SnowSummary
{
    private const SEASON_COMPLETE_SHARE = 0.85;

    // SQL: this row's snowfall (or depth) exists and carries no quality flag.
    private const OK_SNOWFALL = "snowfall_in IS NOT NULL AND SUBSTRING_INDEX(SUBSTRING_INDEX(snowfall_attr, ',', 2), ',', -1) = ''";

    private const OK_DEPTH = "snow_depth_in IS NOT NULL AND SUBSTRING_INDEX(SUBSTRING_INDEX(snow_depth_attr, ',', 2), ',', -1) = ''";

    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return array<string, mixed> */
    public function forStation(string $station, int $monthlyFromYear): array
    {
        $lastRow = (array) $this->db->selectOne('SELECT MAX(`date`) AS d FROM snow_observations WHERE station_id = :s AND snowfall_in IS NOT NULL', [':s' => $station]);
        $lastSnowDate = ($lastRow['d'] ?? null) ?: null;
        if ($lastSnowDate === null) {
            return ['station' => $station, 'through' => null, 'seasons' => [], 'records' => null, 'monthly_depth' => []];
        }

        // One row per snow season.
        $rows = $this->db->select(sprintf(
            'SELECT YEAR(`date`) - (MONTH(`date`) < 7) AS season_year,
                ROUND(SUM(CASE WHEN %s THEN snowfall_in END), 1) AS snowfall_in,
                SUM(CASE WHEN %s THEN 1 ELSE 0 END) AS days_reported,
                MAX(CASE WHEN %s THEN snow_depth_in END) AS max_depth_in
           FROM snow_observations WHERE station_id = :s
          GROUP BY season_year ORDER BY season_year',
            self::OK_SNOWFALL, self::OK_SNOWFALL, self::OK_DEPTH
        ), [':s' => $station]);
        $last = new DateTimeImmutable($lastSnowDate);
        $seasons = [];
        foreach ($rows as $r) {
            $y = (int) $r->season_year;
            $start = new DateTimeImmutable(sprintf('%04d-07-01', $y));
            $end = new DateTimeImmutable(sprintf('%04d-06-30', $y + 1));
            $inProgress = $end > $last;
            $observedUntil = $inProgress ? $last : $end;
            $expected = max(1, (int) $start->diff($observedUntil)->days + 1);
            $reported = (int) $r->days_reported;
            $seasons[] = [
                'season' => sprintf('%d-%02d', $y, ($y + 1) % 100),
                'start_year' => $y,
                'snowfall_in' => $r->snowfall_in !== null ? (float) $r->snowfall_in : null,
                'max_depth_in' => $r->max_depth_in !== null ? (float) $r->max_depth_in : null,
                'days_reported' => $reported,
                'days_expected' => $expected,
                'in_progress' => $inProgress,
                'complete' => ! $inProgress && $reported >= self::SEASON_COMPLETE_SHARE * $expected,
            ];
        }

        $record = function (string $col, string $ok) use ($station): ?array {
            $row = $this->db->selectOne("SELECT `date`, $col AS v FROM snow_observations WHERE station_id = :s AND $ok ORDER BY $col DESC, `date` ASC LIMIT 1", [':s' => $station]);

            return $row ? ['date' => $row->date, 'inches' => (float) $row->v] : null;
        };

        // Average snow depth by calendar month over days with a depth reading, from $monthlyFromYear.
        $byMonth = [];
        foreach ($this->db->select(sprintf(
            'SELECT MONTH(`date`) AS month, ROUND(AVG(snow_depth_in), 2) AS avg_depth_in, COUNT(*) AS days
           FROM snow_observations WHERE station_id = :s AND `date` >= :from AND %s GROUP BY month',
            self::OK_DEPTH
        ), [':s' => $station, ':from' => sprintf('%04d-01-01', $monthlyFromYear)]) as $r) {
            $byMonth[(int) $r->month] = $r;
        }
        $monthly = [];
        for ($i = 1; $i <= 12; $i++) {
            $monthly[] = [
                'month' => $i,
                'avg_depth_in' => isset($byMonth[$i]) ? (float) $byMonth[$i]->avg_depth_in : null,
                'days' => isset($byMonth[$i]) ? (int) $byMonth[$i]->days : 0,
            ];
        }

        return [
            'station' => $station,
            'through' => $lastSnowDate,
            'monthly_from_year' => $monthlyFromYear,
            'seasons' => $seasons,
            'records' => [
                'snowfall_in_a_day' => $record('snowfall_in', self::OK_SNOWFALL),
                'snow_depth_in' => $record('snow_depth_in', self::OK_DEPTH),
            ],
            'monthly_depth' => $monthly,
        ];
    }
}
