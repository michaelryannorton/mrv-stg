<?php

namespace App\Services\Weather;

use App\Support\Weather\LegacyRound;
use Illuminate\Database\ConnectionInterface;

/**
 * Climate projection summary for one city (port of api/inc/climate_summary.php, D8): from the most recent snapshot that has all seven
 * models for the city, a yearly series per model and a month-by-month comparison of a baseline period against a recent-future period.
 * Yearly means are weighted by the days each month rests on; only years with every month present (360 or more days) are returned.
 * The SQL is the legacy SQL, word for word, so the real MySQL gives the legacy answers.
 */
class ClimateSummary
{
    private const MODELS = 7;

    private const BASELINE = [1991, 2020]; // the standard 30-year reference period

    private const FUTURE = [2035, 2049]; // the last 15 years every model has

    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return array<string, mixed> */
    public function forCity(int $cityId): array
    {
        $found = $this->db->select('SELECT snapshot FROM climate_fetch_progress WHERE city_id = :c GROUP BY snapshot HAVING COUNT(*) = :n ORDER BY snapshot DESC LIMIT 1', [':c' => $cityId, ':n' => self::MODELS]);
        if (! $found) {
            return ['city_id' => $cityId, 'snapshot' => null];
        }
        $snapshot = $found[0]->snapshot;

        $annual = [];
        $years = [];
        $rows = $this->db->select(
            'SELECT model, `year`, SUM(days) AS days,
                SUM(tmax_mean_f * days) / SUM(days) AS tmax, SUM(tmin_mean_f * days) / SUM(days) AS tmin,
                SUM(precip_in) AS precip, SUM(days_tmax_ge_100) AS h100, SUM(days_tmax_ge_105) AS h105,
                SUM(days_tmin_le_32) AS l32, SUM(days_tmin_ge_70) AS l70, SUM(days_precip_ge_1) AS p1
           FROM climate_monthly WHERE snapshot = :s AND city_id = :c
          GROUP BY model, `year` HAVING SUM(days) >= 360 ORDER BY model, `year`',
            [':s' => $snapshot, ':c' => $cityId]
        );
        foreach ($rows as $r) {
            $y = (int) $r->year;
            $years[$y] = true;
            $annual[$r->model][$y] = [
                'tmax_f' => LegacyRound::round((float) $r->tmax, 2), 'tmin_f' => LegacyRound::round((float) $r->tmin, 2), 'precip_in' => LegacyRound::round((float) $r->precip, 2),
                'days_ge_100' => (int) $r->h100, 'days_ge_105' => (int) $r->h105, 'days_le_32' => (int) $r->l32,
                'nights_ge_70' => (int) $r->l70, 'days_precip_ge_1' => (int) $r->p1,
            ];
        }
        $years = array_keys($years);
        sort($years);
        $models = array_keys($annual);
        sort($models);
        $series = [];
        foreach ($models as $m) {
            $series[$m] = array_map(fn ($y) => $annual[$m][$y] ?? null, $years);
        }

        // Month-by-month climatology of two periods, per model, weighted by days.
        $period = function (array $range) use ($snapshot, $cityId): array {
            $out = [];
            $rows = $this->db->select(
                'SELECT model, `month`, SUM(tmax_mean_f * days) / SUM(days) AS tmax, SUM(tmin_mean_f * days) / SUM(days) AS tmin,
                    SUM(precip_in) / COUNT(DISTINCT `year`) AS precip
               FROM climate_monthly WHERE snapshot = :s AND city_id = :c AND `year` BETWEEN :a AND :b
              GROUP BY model, `month` ORDER BY model, `month`',
                [':s' => $snapshot, ':c' => $cityId, ':a' => $range[0], ':b' => $range[1]]
            );
            foreach ($rows as $r) {
                $out[$r->model][(int) $r->month - 1] = ['tmax_f' => LegacyRound::round((float) $r->tmax, 2), 'tmin_f' => LegacyRound::round((float) $r->tmin, 2), 'precip_in' => LegacyRound::round((float) $r->precip, 3)];
            }

            return $out;
        };
        $base = $period(self::BASELINE);
        $future = $period(self::FUTURE);
        $monthly = [];
        foreach ($models as $m) {
            $monthly[$m] = ['baseline' => array_values($base[$m] ?? []), 'future' => array_values($future[$m] ?? [])];
        }

        $when = $this->db->select('SELECT MIN(queried_on) a, MAX(queried_on) b FROM climate_monthly WHERE snapshot = :s AND city_id = :c', [':s' => $snapshot, ':c' => $cityId])[0];
        $all = array_map(fn ($r) => $r->snapshot, $this->db->select('SELECT snapshot FROM climate_fetch_progress GROUP BY snapshot ORDER BY snapshot'));

        return [
            'city_id' => $cityId, 'snapshot' => $snapshot, 'queried_from' => $when->a, 'queried_to' => $when->b,
            'snapshots_started' => $all,
            'baseline' => self::BASELINE, 'future' => self::FUTURE,
            'models' => $models, 'years' => $years, 'annual' => $series, 'monthly' => $monthly,
        ];
    }
}
