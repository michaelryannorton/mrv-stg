<?php

namespace App\Http\Controllers\Data;

use App\Http\Controllers\Controller;
use App\Support\Weather\WeatherTables;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The legacy API's query.php (api/v1/query.php), served by the app itself (port phase 7): a read-only reader of the whitelisted
 * weather tables. Same request shapes and the same answers: a JSON array of rows, `columns=` to project (the identity columns are
 * always added), `city_id=` as one id or a comma list, the key filters `site_id`, `station_id` and `area_fips`, `from` and `to`
 * on the table's own date column, ordered by that column, `limit` default 5,000 and capped at 500,000. Table and column names
 * come only from WeatherTables; every value is a bound parameter.
 *
 * Differences from the legacy file, all in cases it leaves undefined: rows with the same date come out in a fixed order (the
 * table's unique key) instead of the database's; a negative `limit` is 0; a value given as an array is ignored; text that is not
 * valid UTF-8 is written with replacement characters instead of failing the whole response.
 */
class QueryController extends Controller
{
    private const AUDIT_COLUMNS = ['created_at', 'updated_at'];

    private const IDENTITY_COLUMNS = ['city_id', 'date', 'reading_date', 'event_time', 'observed_at'];

    private const KEY_FILTERS = ['site_id', 'station_id', 'area_fips'];

    private const DATE_COLUMNS = ['date', 'reading_date', 'event_time', 'observed_at'];

    private const MAX_LIMIT = 500000;

    public function __invoke(Request $request): JsonResponse|StreamedResponse
    {
        // Laravel turns an empty value into null; the legacy file saw an empty string (a set parameter), and `limit=` meant 0.
        $input = array_map(fn ($v) => $v ?? '', $request->query());
        $table = $input['table'] ?? '';
        if (! WeatherTables::has($table)) {
            return response()->json(['error' => 'unknown table', 'allowed' => WeatherTables::names()], 400);
        }

        $cols = WeatherTables::columns($table);
        $readable = array_merge($cols, self::AUDIT_COLUMNS);
        $query = DB::connection('weather')->table($table);

        // Optional column projection, whitelisted against the table's columns and the read-only audit columns.
        $select = ['*'];
        if (isset($input['columns'])) {
            $requested = is_string($input['columns']) ? array_filter(array_map('trim', explode(',', $input['columns']))) : ['(not a list)'];
            $invalid = array_diff($requested, $readable);
            if ($invalid) {
                return response()->json(['error' => 'unknown column(s)', 'invalid' => array_values($invalid), 'allowed' => $readable], 400);
            }
            // Rows are meaningless without knowing which city or day they belong to, so the identity columns always come first.
            $identity = array_values(array_intersect(self::IDENTITY_COLUMNS, $cols));
            $select = array_values(array_unique(array_merge($identity, $requested ?: $cols)));
        }
        $query->select($select);

        // One id or a comma-separated list; every id goes through (int) before it is bound.
        if (isset($input['city_id']) && is_string($input['city_id']) && in_array('city_id', $cols, true)) {
            $ids = array_values(array_filter(array_map(fn ($v) => (int) trim($v), explode(',', $input['city_id'])), fn ($v) => $v > 0));
            if ($ids) {
                $query->whereIn('city_id', $ids);
            }
        }

        foreach (self::KEY_FILTERS as $key) {
            if (isset($input[$key]) && is_string($input[$key]) && in_array($key, $cols, true)) {
                $query->where($key, '=', $input[$key]);
            }
        }

        // Whichever date-ish column this table actually has, if any.
        $dateColumn = null;
        foreach (self::DATE_COLUMNS as $candidate) {
            if (in_array($candidate, $cols, true)) {
                $dateColumn = $candidate;
                break;
            }
        }

        if ($dateColumn !== null) {
            if (isset($input['from']) && is_string($input['from'])) {
                $query->where($dateColumn, '>=', $input['from']);
            }
            if (isset($input['to']) && is_string($input['to'])) {
                $query->where($dateColumn, '<=', $input['to']);
            }
            $query->orderBy($dateColumn);
            foreach (WeatherTables::SCHEMAS[$table]['unique_key'] ?? [] as $keyColumn) {
                if ($keyColumn !== $dateColumn && in_array($keyColumn, $cols, true)) {
                    $query->orderBy($keyColumn);
                }
            }
        }

        $limit = max(0, min((int) ($input['limit'] ?? 5000), self::MAX_LIMIT));
        $query->limit($limit);

        return $this->stream($query->cursor());
    }

    /** Writes the rows as one JSON array, one row at a time, so the bulk requests never build a 34 MB string in memory. */
    private function stream(iterable $rows): StreamedResponse
    {
        return response()->stream(function () use ($rows) {
            echo '[';
            $first = true;
            $count = 0;
            foreach ($rows as $row) {
                echo ($first ? '' : ',').json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE);
                $first = false;
                if (++$count % 2000 === 0) {
                    flush();
                }
            }
            echo ']';
        }, 200, ['Content-Type' => 'application/json', 'X-Accel-Buffering' => 'no']);
    }
}
