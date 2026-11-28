<?php

namespace App\Services\Places;

use DOMDocument;
use DOMXPath;

/**
 * What a feed's own record says about where an event is, what it costs and who it is for. Read from
 * the kept record (source_records.raw_text), so collection and the later filling of stored events
 * share one reading. Anything not clearly said is left unknown.
 */
class FeedFacts
{
    /** Audience name => words in a feed's category names that mean it. */
    private const AUDIENCES = [
        'Seniors' => '/\bseniors?\b|\b(55|60|65)\s*\+/i',
        'Children' => '/\b(kids?|child(ren)?|toddlers?|preschool(ers)?|bab(y|ies))\b/i',
        'Families' => '/\bfamil(y|ies)\b/i',
        'Adults' => '/(?<!young )\badults?\b/i',
        'All Ages' => '/\ball[ -]ages\b/i',
    ];

    private const ONLINE = '/\b(zoom|online|virtual(ly)?|webinar|microsoft teams|google meet)\b/i';

    /**
     * @return array{place: ?array{name: string, street: ?string, city: ?string, state: ?string,
     *     zip: ?string, lat: ?float, lng: ?float}, online: bool, is_free: ?bool, price_min: ?float,
     *     price_max: ?float, audiences: list<string>}
     */
    public static function from(string $collector, ?string $raw, ?string $location): array
    {
        $facts = ['place' => null, 'online' => false, 'is_free' => null, 'price_min' => null, 'price_max' => null, 'audiences' => []];
        $data = $raw !== null && in_array($collector, ['tribe_events', 'squarespace_events'], true) ? json_decode($raw, true) : null;

        $place = match ($collector) {
            'tribe_events' => self::tribePlace(is_array($data) ? $data : []),
            'squarespace_events' => self::squarespacePlace(is_array($data) ? $data : []),
            'growthzone' => self::growthZonePlace($raw, $location),
            default => self::linePlace($location, $raw),
        };

        if ($collector === 'tribe_events' && is_array($data)) {
            $facts = [...$facts, ...self::cost($data['cost'] ?? null)];
            $facts['audiences'] = self::audiences(array_map(fn ($c) => is_array($c) ? (string) ($c['name'] ?? '') : '', (array) ($data['categories'] ?? [])));
        }

        $online = ($data['is_virtual'] ?? false) === true
            || (! Address::hasNumber($place['street'] ?? null) && preg_match(self::ONLINE, (string) $location) === 1);

        if ($online) {
            return [...$facts, 'online' => true];
        }

        // A name alone ("Room B") is not a place to put on a map or to filter by city.
        if ($place !== null && ($place['street'] !== null || $place['city'] !== null)) {
            $place['name'] ??= $place['street'] ?? $place['city'];
            $facts['place'] = $place;
        }

        return $facts;
    }

    private static function place(?string $name, ?string $street, ?string $city, ?string $state, ?string $zip, mixed $lat = null, mixed $lng = null): array
    {
        // Coordinates outside the United States are a mistake in the feed (or an unset map).
        $ok = is_numeric($lat) && is_numeric($lng) && $lat > 24 && $lat < 50 && $lng > -125 && $lng < -66;

        return [
            'name' => $name, 'street' => $street, 'city' => $city, 'state' => $state, 'zip' => $zip,
            'lat' => $ok ? round((float) $lat, 6) : null, 'lng' => $ok ? round((float) $lng, 6) : null,
        ];
    }

    private static function tribePlace(array $data): ?array
    {
        $venue = $data['venue'] ?? null;

        if (! is_array($venue) || $venue === []) {
            return null;
        }

        return self::place(
            Address::text($venue['venue'] ?? null),
            Address::street($venue['address'] ?? null),
            Address::city($venue['city'] ?? null),
            Address::state($venue['state'] ?? $venue['stateprovince'] ?? $venue['province'] ?? null),
            Address::zip($venue['zip'] ?? null),
            $venue['geo_lat'] ?? null,
            $venue['geo_lng'] ?? null,
        );
    }

    private static function squarespacePlace(array $data): ?array
    {
        $location = $data['location'] ?? null;

        if (! is_array($location)) {
            return null;
        }

        // "Town, CA, 90000"
        $second = array_map('trim', explode(',', (string) ($location['addressLine2'] ?? '')));
        $lat = $location['mapLat'] ?? null;
        $lng = $location['mapLng'] ?? null;

        // Squarespace puts an unset map at its own New York office.
        if (is_numeric($lat) && abs($lat - 40.72) < 0.01) {
            $lat = $lng = null;
        }

        return self::place(
            Address::text($location['addressTitle'] ?? null),
            Address::street($location['addressLine1'] ?? null),
            Address::city($second[0] ?? null),
            Address::state($second[1] ?? null),
            Address::zip($second[2] ?? ($second[1] ?? null)),
            $lat,
            $lng,
        );
    }

    private static function growthZonePlace(?string $raw, ?string $location): ?array
    {
        $parsed = self::linePlace($location, null);

        if ($raw === null || trim($raw) === '') {
            return $parsed;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$raw, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        $part = fn (string $property) => $xpath->query('//*[@itemprop="location"]//*[@itemprop="'.$property.'"]')->item(0)?->textContent;

        $street = Address::street($part('streetAddress'));

        if ($street === null) {
            return $parsed;
        }

        // The venue's name is the part of the written place before its street number.
        $name = $location === null ? null : Address::text(preg_split('/(?:^|,\s*)\d+\s/u', $location)[0]);

        return self::place($name, $street, Address::city($part('addressLocality')), Address::state($part('addressRegion')), Address::zip($part('postalCode')));
    }

    /** A calendar's free-text location, with the coordinates some calendar programs add. */
    private static function linePlace(?string $location, ?string $raw): ?array
    {
        $lat = $lng = null;

        // The record is folded at 75 characters; unfold before looking.
        $unfolded = preg_replace('/\r?\n[ \t]/', '', (string) $raw);

        if (preg_match('/^GEO(?:;[^:]*)?:(-?[\d.]+);(-?[\d.]+)/m', $unfolded, $m)
            || preg_match('/^X-APPLE-STRUCTURED-LOCATION[^\r\n]*:geo:(-?[\d.]+),(-?[\d.]+)/m', $unfolded, $m)) {
            [$lat, $lng] = [$m[1], $m[2]];
        }

        $parsed = Address::parse($location);

        if ($parsed === null) {
            return $lat === null ? null : self::place(Address::text($location), null, null, null, null, $lat, $lng);
        }

        return self::place($parsed['name'], $parsed['street'], $parsed['city'], $parsed['state'], $parsed['zip'], $lat, $lng);
    }

    /** "Free", "$5", "$5 – $10". A range that includes free, or anything else, is left unknown. */
    private static function cost(mixed $cost): array
    {
        $text = Address::text($cost);

        if ($text === null) {
            return [];
        }

        if (preg_match('/^(free( admission| to attend| event)?|\$\s*0(\.00)?)[.!]?$/i', $text)) {
            return ['is_free' => true];
        }

        if (preg_match('/^\$\s*\d+(\.\d{1,2})?(\s*(-|–|—|to)\s*\$?\s*\d+(\.\d{1,2})?)?$/u', $text)) {
            preg_match_all('/\d+(?:\.\d{1,2})?/', $text, $m);
            $amounts = array_map('floatval', $m[0]);

            return min($amounts) > 0
                ? ['is_free' => false, 'price_min' => min($amounts), 'price_max' => max($amounts)]
                : [];
        }

        return [];
    }

    /** @return list<string> */
    private static function audiences(array $categories): array
    {
        $found = [];

        foreach (self::AUDIENCES as $audience => $pattern) {
            foreach ($categories as $category) {
                if (preg_match($pattern, html_entity_decode($category, ENT_QUOTES | ENT_HTML5))) {
                    $found[] = $audience;
                    break;
                }
            }
        }

        return $found;
    }
}
