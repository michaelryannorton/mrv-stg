<?php

namespace App\Support;

/**
 * Cleans a web address received from outside the app (a feed, a scraped page, a form) before it
 * is stored. Some feeds HTML-escape the address inside a field that is not HTML, so a stored
 * "?a=1&amp;b=2" would be linked as a different query string than the one the organizer
 * published. This does not judge whether the address is safe to link; Event::details_url does
 * that at the point of use.
 */
class ExternalUrl
{
    /** More passes than any real feed needs; stops a pathological value from looping. */
    private const MAX_DECODE_PASSES = 3;

    public static function clean(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        // A literal "&amp;" never belongs in a real address, so decode until the value is stable
        // (a feed that escaped twice gives "&amp;amp;").
        for ($pass = 0; $pass < self::MAX_DECODE_PASSES; $pass++) {
            $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($decoded === $url) {
                break;
            }

            $url = $decoded;
        }

        // Control characters (including tabs and newlines) are never valid inside an address.
        $url = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $url) ?? '');

        return $url === '' ? null : $url;
    }
}
