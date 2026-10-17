<?php

namespace Tests\Support;

use App\Support\Weather\WeatherTables;
use Illuminate\Support\Facades\DB;

/**
 * An in-memory SQLite stand-in for the weather database (port phase 7). Tables are created from the whitelist (no column types:
 * SQLite keeps each value as the storage class it is given, so a DECIMAL that arrives as the string "69.8" stays a string and an
 * integer stays an integer, as they do through MySQL's PDO driver). The legacy summaries' MySQL functions are registered as
 * small shims so the legacy SQL runs unchanged.
 */
trait WeatherDatabase
{
    protected function useWeatherDatabase(): void
    {
        config(['database.connections.weather' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('weather');
        $db = DB::connection('weather');

        foreach (WeatherTables::names() as $table) {
            $columns = array_unique(array_merge(['id'], WeatherTables::columns($table), ['created_at', 'updated_at']));
            $db->statement('CREATE TABLE "'.$table.'" ('.implode(', ', array_map(fn ($c) => '"'.$c.'"', $columns)).')');
        }
        $db->statement('CREATE TABLE "climate_fetch_progress" ("snapshot", "city_id", "model")');

        $pdo = $db->getPdo();
        $pdo->sqliteCreateFunction('YEAR', fn ($d) => $d === null ? null : (int) substr((string) $d, 0, 4), 1);
        $pdo->sqliteCreateFunction('MONTH', fn ($d) => $d === null ? null : (int) substr((string) $d, 5, 2), 1);
        $pdo->sqliteCreateFunction('SUBSTRING_INDEX', function ($str, $delim, $count) {
            if ($str === null) {
                return null;
            }
            $parts = explode((string) $delim, (string) $str);
            if ($count > 0) {
                return implode((string) $delim, array_slice($parts, 0, $count));
            }

            return implode((string) $delim, array_slice($parts, $count));
        }, 3);
    }

    /**
     * Loads rows into a table. The table is (re)created with the rows' own columns in the rows' own order, then the whitelisted
     * columns the rows lack: the saved responses carry the real table's column order, and a table without `updated_at` must not
     * grow one.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $types  Column types to declare (the summaries add up DECIMAL columns, which MySQL treats as numbers; SQLite treats text in an untyped column as text).
     */
    protected function insertWeatherRows(string $table, array $rows, array $types = []): void
    {
        $db = DB::connection('weather');
        $known = $table === 'climate_fetch_progress' ? [] : WeatherTables::columns($table);
        $columns = $rows ? array_values(array_unique(array_merge(array_keys($rows[0]), $known))) : null;
        if ($columns !== null) {
            $db->statement('DROP TABLE "'.$table.'"');
            $db->statement('CREATE TABLE "'.$table.'" ('.implode(', ', array_map(fn ($c) => '"'.$c.'"'.(isset($types[$c]) ? ' '.$types[$c] : ''), $columns)).')');
        }
        if (! $rows) {
            return;
        }
        $width = max(1, count($rows[0]));
        foreach (array_chunk($rows, max(1, intdiv(20000, $width))) as $chunk) {
            $db->table($table)->insert($chunk);
        }
    }

    /** @return mixed The decoded contents of a gzipped JSON fixture under tests/Fixtures/weather. */
    protected function weatherFixture(string $name): mixed
    {
        static $cache = [];

        return $cache[$name] ??= json_decode(gzdecode(file_get_contents(base_path('tests/Fixtures/weather/'.$name))), true, 512, JSON_THROW_ON_ERROR);
    }
}
