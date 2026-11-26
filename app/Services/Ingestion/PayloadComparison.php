<?php

namespace App\Services\Ingestion;

/**
 * Whether two source payloads say the same thing. EventIngestor uses it to write a record only when an
 * item changed, and ingest:prune-source-records uses it to find repeats, so the two cannot disagree.
 */
final class PayloadComparison
{
    /**
     * A missing payload is never the same as anything. Key order is ignored (MySQL's JSON type stores
     * keys in its own order); values are compared strictly, in the form they take once stored.
     *
     * @param  array<array-key, mixed>|null  $a
     * @param  array<array-key, mixed>|null  $b
     */
    public static function same(?array $a, ?array $b): bool
    {
        return $a !== null && $b !== null && self::stored($a) === self::stored($b);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private static function stored(array $payload): array
    {
        return self::sorted(json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sorted(array $value): array
    {
        ksort($value);

        return array_map(fn (mixed $v) => is_array($v) ? self::sorted($v) : $v, $value);
    }
}
