<?php

namespace App\Services\Places;

/** Small rules for reading and comparing addresses, used by ingestion and by the geocoder. */
class Address
{
    /** One line of plain text, or null. */
    public static function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5);
        $text = trim(preg_replace('/\s+/u', ' ', $text), " \t\n,;");

        return $text === '' ? null : mb_substr($text, 0, 250);
    }

    /** A city as shown: capitals are only changed when the feed sent all of one case. */
    public static function city(mixed $value): ?string
    {
        $city = self::text($value);

        if ($city === null || preg_match('/\d/', $city)) {
            return null;
        }

        $city = rtrim($city, '.');

        return $city === mb_strtolower($city) || $city === mb_strtoupper($city)
            ? mb_convert_case($city, MB_CASE_TITLE)
            : $city;
    }

    public static function state(mixed $value): ?string
    {
        $state = self::text($value);

        if ($state === null) {
            return null;
        }

        return in_array(mb_strtolower(rtrim($state, '.')), ['ca', 'calif', 'california'], true) ? 'CA' : $state;
    }

    public static function zip(mixed $value): ?string
    {
        return preg_match('/\b(\d{5})(?:-\d{4})?\b/', (string) self::text($value), $m) ? $m[1] : null;
    }

    /** Only the street line of a field that may repeat the city and state after a comma. */
    public static function street(mixed $value): ?string
    {
        $street = self::text($value);

        return $street === null ? null : self::text(explode(',', $street)[0]);
    }

    /** A street line the geocoder can use starts with a building number. */
    public static function hasNumber(?string $street): bool
    {
        return $street !== null && preg_match('/^\d+\S*\s+\S/', $street) === 1;
    }

    /**
     * Reads "Venue, 123 Main St, Suite 2, City, CA 92392, USA" as calendars and chambers write it.
     *
     * @return array{name: ?string, street: string, city: ?string, state: ?string, zip: ?string}|null
     */
    public static function parse(?string $line): ?array
    {
        $line = self::text($line);

        if ($line === null) {
            return null;
        }

        $pattern = '/(?:^|,\s*)(\d+[^,]*),\s*(?:(?:Suite|Ste\.?|Unit|Bldg\.?|Building|Room|Rm\.?|#)[^,]*,\s*)?([A-Za-z .\']+),\s*(CA|Calif\.?|California)\b\.?\s*(\d{5})?/iu';

        if (! preg_match($pattern, $line, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return [
            'name' => self::text(substr($line, 0, $m[0][1])),
            'street' => self::text($m[1][0]),
            'city' => self::city($m[2][0]),
            'state' => self::state($m[3][0]),
            'zip' => $m[4][0] ?? null,
        ];
    }

    /** What two spellings of the same thing have in common. */
    public static function compare(?string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string) $value));
    }
}
