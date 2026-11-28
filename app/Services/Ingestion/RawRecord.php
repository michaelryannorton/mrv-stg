<?php

namespace App\Services\Ingestion;

/** One feed item as the feed sent it, as text. */
class RawRecord
{
    public static function json(mixed $item): string
    {
        return (string) json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
