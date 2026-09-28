<?php

namespace App\Services;

use Carbon\Carbon;
use DOMDocument;
use DOMXPath;

/**
 * Pulls whatever a page will hand over about a single event, for the clipper (section 6) and,
 * later, the public submission form (section 5) — both are specified to share this same
 * structured-data/DOM-heuristic extractor. No LLM step, per the Phase 1 plan's explicit scope
 * cut: JSON-LD/Schema.org Event first, OpenGraph second, bare <title> last. Anything not found
 * is simply absent from the returned array — the caller's Quick Add form comes back blank for
 * whatever this couldn't fill in, rather than guessing.
 */
class EventExtractor
{
    public static function extract(string $html, string $sourceUrl): array
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $dom->loadHTML($html);
        libxml_clear_errors();

        $event = self::findJsonLdEvent($dom);

        if ($event !== null) {
            return self::fromJsonLd($event, $sourceUrl);
        }

        return self::fromOpenGraph($dom, $sourceUrl);
    }

    /**
     * Every <script type="application/ld+json"> block on the page, decoded and flattened (a block
     * may itself be an array of nodes, or an object using @graph to bundle several), searching for
     * the first node whose @type mentions "Event" — covers Event's many schema.org subtypes
     * (MusicEvent, TheaterEvent, Festival, ...) without needing an exhaustive list of them.
     */
    private static function findJsonLdEvent(DOMDocument $dom): ?array
    {
        foreach ($dom->getElementsByTagName('script') as $script) {
            if (strtolower($script->getAttribute('type')) !== 'application/ld+json') {
                continue;
            }

            $decoded = json_decode($script->textContent, true);

            if (! is_array($decoded)) {
                continue;
            }

            foreach (self::flattenJsonLdNodes($decoded) as $node) {
                $type = $node['@type'] ?? null;
                $type = is_array($type) ? implode(',', $type) : (string) $type;

                if (str_contains(strtolower($type), 'event')) {
                    return $node;
                }
            }
        }

        return null;
    }

    private static function flattenJsonLdNodes(array $decoded): array
    {
        // A single node (has @type) vs. a list of nodes vs. a {@graph: [...]} wrapper.
        if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
            return $decoded['@graph'];
        }

        if (isset($decoded['@type'])) {
            return [$decoded];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    private static function fromJsonLd(array $event, string $sourceUrl): array
    {
        $location = $event['location'] ?? null;
        $location = is_array($location) ? (is_array($location[0] ?? null) ? $location[0] : $location) : null;

        $prefilled = array_filter([
            'title' => self::cleanText($event['name'] ?? null),
            'description' => self::cleanText($event['description'] ?? null),
            'canonical_url' => $event['url'] ?? $sourceUrl,
            'location_name_override' => self::cleanText($location['name'] ?? null),
            'address_override' => self::formatAddress($location['address'] ?? null),
        ], fn ($value) => $value !== null && $value !== '');

        $start = self::normalizeDateTime($event['startDate'] ?? null);
        $end = self::normalizeDateTime($event['endDate'] ?? null);

        if ($start !== null) {
            $prefilled['start_at'] = $start['value'];
            $prefilled['all_day'] = $start['all_day'];
        }

        if ($end !== null) {
            $prefilled['end_at'] = $end['value'];
        }

        return $prefilled;
    }

    private static function fromOpenGraph(DOMDocument $dom, string $sourceUrl): array
    {
        $xpath = new DOMXPath($dom);

        $meta = function (string $property) use ($xpath): ?string {
            $node = $xpath->query("//meta[@property='{$property}']")->item(0)
                ?? $xpath->query("//meta[@name='{$property}']")->item(0);

            return $node ? self::cleanText($node->getAttribute('content')) : null;
        };

        $title = $meta('og:title') ?? self::cleanText($dom->getElementsByTagName('title')->item(0)?->textContent ?? null);

        return array_filter([
            'title' => $title,
            'description' => $meta('og:description') ?? $meta('description'),
            'canonical_url' => $meta('og:url') ?? $sourceUrl,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private static function cleanText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5));

        return $clean === '' ? null : $clean;
    }

    private static function formatAddress(mixed $address): ?string
    {
        if (is_string($address)) {
            return self::cleanText($address);
        }

        if (! is_array($address)) {
            return null;
        }

        $parts = array_filter([
            $address['streetAddress'] ?? null,
            $address['addressLocality'] ?? null,
            $address['addressRegion'] ?? null,
            $address['postalCode'] ?? null,
        ]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * Reformats an ISO 8601 date/datetime string into the bare "Y-m-d\TH:i" shape the edit form's
     * datetime-local inputs need — see edit.tsx's own toDatetimeLocalValue for why that shape is
     * required. Deliberately does not attempt to resolve the source's timezone into an IANA name;
     * Carbon::parse keeps whatever offset the string carries when formatting it back out, so the
     * wall-clock digits round-trip correctly even though the form's timezone field still just shows
     * the schema's regional default (America/Los_Angeles) for Michael to correct if it's wrong.
     */
    private static function normalizeDateTime(?string $iso): ?array
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        try {
            $allDay = ! str_contains($iso, 'T');
            $parsed = Carbon::parse($iso);
        } catch (\Throwable) {
            return null;
        }

        return [
            'value' => $parsed->format('Y-m-d\TH:i'),
            'all_day' => $allDay,
        ];
    }
}
