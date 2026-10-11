<?php

namespace App\Support;

/** A rough, human wait time for rate-limit messages: it says when to come back without giving exact limits. */
class WaitPhrase
{
    public static function for(int $seconds): string
    {
        return match (true) {
            $seconds <= 60 => 'a minute',
            $seconds <= 3300 => ceil($seconds / 60).' minutes',
            default => self::hours(max(1, (int) round($seconds / 3600))),
        };
    }

    private static function hours(int $hours): string
    {
        return $hours === 1 ? '1 hour' : $hours.' hours';
    }
}
