<?php

namespace App\Services\Ingestion;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Squarespace "events" collection pages. Any Squarespace events page also serves its own
 * structured data when asked for `?format=json`: an `upcoming` list of event items (epoch-
 * millisecond start/end, title, body/excerpt HTML, a structured address, a relative URL) plus the
 * site's IANA time zone in `website.timeZone`. No auth and no per-site setup beyond the
 * collection page's URL, so one collector covers every Squarespace events page — the feed_url on
 * the source is the plain page URL (e.g. https://www.wrightwoodarts.org/events), without the
 * format parameter. Found while looking for a second platform family after Tribe Events; see
 * logs/2026 1008 1645 Second Collector Discovery, Approval Warning, And Unresolved Sources.md.
 */
class SquarespaceEventsCollector
{
    /** Safety cap on pagination; a community events page has nowhere near this many upcoming pages. */
    private const MAX_PAGES = 5;

    /**
     * @return list<array{external_id: string, title: string, description: ?string,
     *     start_at: Carbon, end_at: ?Carbon, all_day: bool, location: ?string, url: ?string,
     *     timezone: string}>
     */
    public function fetch(string $feedUrl, int $windowDays = 180): array
    {
        $parts = parse_url($feedUrl);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        $horizon = now()->addDays($windowDays);

        $occurrences = [];
        $seen = [];
        $url = $feedUrl;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            // Not Http::get($url, ['format' => 'json']): Guzzle's query option replaces whatever
            // query string the URL already has, which would drop the pagination offset.
            $response = Http::timeout(20)->get($url.(str_contains($url, '?') ? '&' : '?').'format=json');

            if (! $response->successful()) {
                throw new RuntimeException("Squarespace events fetch failed with HTTP {$response->status()}");
            }

            $payload = $response->json();

            if (! is_array($payload) || ! array_key_exists('upcoming', $payload)) {
                throw new RuntimeException('Squarespace events fetch returned no "upcoming" list; the URL may not be an events collection.');
            }

            $timezone = $this->timezone($payload);
            $baseUrl = rtrim($payload['website']['baseUrl'] ?? $origin, '/');
            $upcoming = $payload['upcoming'] ?? [];

            $newOnPage = 0;

            foreach ($upcoming as $item) {
                $normalized = $this->normalize($item, $timezone, $baseUrl);

                if ($normalized === null || isset($seen[$normalized['external_id']])) {
                    continue;
                }

                $seen[$normalized['external_id']] = true;
                $newOnPage++;

                if ($normalized['start_at']->lte($horizon)) {
                    $occurrences[] = $normalized;
                }
            }

            // A page that adds nothing new means the site ignored the offset; don't loop on it.
            if ($newOnPage === 0) {
                break;
            }

            // Pagination on these pages is driven mostly by the (much longer) `past` list, so only
            // keep paging while the upcoming list actually filled a page.
            $pageSize = (int) ($payload['pagination']['pageSize'] ?? 0);
            $nextUrl = $payload['pagination']['nextPageUrl'] ?? null;

            if (! ($payload['pagination']['nextPage'] ?? false) || ! $nextUrl || $pageSize === 0 || count($upcoming) < $pageSize) {
                break;
            }

            $url = $origin.$nextUrl;
        }

        return $occurrences;
    }

    private function normalize(array $item, string $timezone, string $baseUrl): ?array
    {
        $startMs = $item['startDate'] ?? $item['structuredContent']['startDate'] ?? null;

        if (empty($item['id']) || empty($startMs)) {
            return null;
        }

        $endMs = $item['endDate'] ?? $item['structuredContent']['endDate'] ?? null;

        $path = $item['fullUrl'] ?? null;

        return [
            'external_id' => (string) $item['id'],
            'title' => $this->cleanText($item['title'] ?? null) ?? 'Untitled event',
            'description' => $this->cleanText($item['body'] ?? null) ?? $this->cleanText($item['excerpt'] ?? null),
            'start_at' => Carbon::createFromTimestampMs((int) $startMs, $timezone),
            'end_at' => $endMs ? Carbon::createFromTimestampMs((int) $endMs, $timezone) : null,
            'all_day' => false,
            'location' => $this->location($item['location'] ?? null),
            'url' => $path ? $baseUrl.$path : null,
            'timezone' => $timezone,
        ];
    }

    /** Squarespace gives the site's IANA zone; anything else means regional local time, as in the other collectors. */
    private function timezone(array $payload): string
    {
        $timezone = $payload['website']['timeZone'] ?? null;

        return $timezone && str_contains($timezone, '/') ? $timezone : 'America/Los_Angeles';
    }

    /** Name plus street address, skipping empty parts and the country (always the same on this calendar). */
    private function location(?array $location): ?string
    {
        if (! $location) {
            return null;
        }

        $parts = array_filter([
            $this->cleanText($location['addressTitle'] ?? null),
            $this->cleanText($location['addressLine1'] ?? null),
            $this->cleanText($location['addressLine2'] ?? null),
        ]);

        return $parts ? implode(', ', $parts) : null;
    }

    private function cleanText(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Squarespace text blocks carry inline <style> rules; strip_tags would keep their contents
        // as visible text, so drop whole style/script elements first.
        $value = preg_replace('#<(style|script)\b[^>]*>.*?</\1>#is', '', $value);
        // Without this, adjacent blocks with no whitespace between them ("</p><p>") run together.
        $value = preg_replace('#<br\s*/?>|</(?:p|div|li|h[1-6]|blockquote)>#i', "\n\n", $value);
        $clean = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5);
        // Body HTML strips down to lots of stray indentation and blank lines; keep paragraph
        // breaks but drop the rest.
        $clean = preg_replace('/[ \t\x{00A0}]+/u', ' ', $clean);
        $clean = implode("\n", array_map('trim', preg_split('/\R/', $clean)));
        $clean = trim(preg_replace('/\n{3,}/', "\n\n", $clean));

        return $clean === '' ? null : $clean;
    }
}
