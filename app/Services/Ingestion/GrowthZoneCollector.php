<?php

namespace App\Services\Ingestion;

use App\Support\PlainText;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * GrowthZone (ChamberMaster) public event calendars, as chambers of commerce use them. The list is
 * read from one calendar page per month; each event's own page carries schema.org markup with the
 * title, UTC start and end, the place and the description. The feed_url is the calendar's /events
 * address. First used for the Greater High Desert Chamber (2026-11-27); see
 * logs/2026 1127 2039 Feed Candidates, And Plan Addition For Automatic Coordinates And Live Distance.md.
 *
 * One request per month and one per event, spaced apart, a few times a day.
 */
class GrowthZoneCollector
{
    /** Pause between requests, so a run is not a burst on the chamber's site. */
    private const PAUSE_MS = 500;

    /** Safety cap on event pages in one run. */
    private const MAX_EVENTS = 200;

    private const TIMEZONE = 'America/Los_Angeles';

    /**
     * @return list<array{external_id: string, title: string, description: ?string,
     *     start_at: Carbon, end_at: ?Carbon, all_day: bool, location: ?string, url: ?string,
     *     timezone: string, raw: string}>
     */
    public function fetch(string $feedUrl, int $windowDays = 180, ?FeedArchive $archive = null): array
    {
        $base = rtrim($feedUrl, '/');
        $now = now(self::TIMEZONE);
        $horizon = $now->copy()->addDays($windowDays);
        $pages = $this->eventPages($base, $now->copy(), $horizon, $archive);

        if ($pages === []) {
            throw new RuntimeException('No events found on the calendar pages; the page layout may have changed.');
        }

        $occurrences = [];
        $failed = 0;

        foreach (array_slice($pages, 0, self::MAX_EVENTS, true) as $id => $url) {
            Sleep::for(self::PAUSE_MS)->milliseconds();
            $response = Http::timeout(20)->get($url);

            if (! $response->successful()) {
                $failed++;

                continue;
            }

            $archive?->add('event-'.$id, $response->body());
            $item = $this->parse($response->body(), (string) $id, $url);

            if ($item === null) {
                $failed++;

                continue;
            }

            if (($item['end_at'] ?? $item['start_at'])->lt($now) || $item['start_at']->gt($horizon)) {
                continue;
            }

            $occurrences[] = $item;
        }

        if ($failed > count($pages) / 2) {
            throw new RuntimeException("{$failed} of ".count($pages).' event pages could not be read.');
        }

        return $occurrences;
    }

    /** @return array<string, string> event page address by event number */
    private function eventPages(string $base, Carbon $from, Carbon $to, ?FeedArchive $archive): array
    {
        $root = preg_replace('#^(https?://[^/]+).*$#i', '$1', $base);
        $pages = [];

        for ($month = $from->startOfMonth(); $month->lte($to); $month->addMonthNoOverflow()) {
            $response = Http::timeout(20)->get($base.'/calendar/'.$month->format('Y-m-d'));

            if (! $response->successful()) {
                throw new RuntimeException("Chamber calendar fetch failed with HTTP {$response->status()}");
            }

            $archive?->add('month-'.$month->format('Y-m'), $response->body());

            preg_match_all('#href="((?:https?://[^"/]+)?/events/details/[a-z0-9\-]*?-(\d+))(?=["?])#i', $response->body(), $links, PREG_SET_ORDER);

            foreach ($links as [, $address, $id]) {
                $url = str_starts_with($address, '/') ? $root.$address : $address;

                // Only pages of the calendar's own site.
                if (str_starts_with($url, $root.'/')) {
                    $pages[$id] ??= $url;
                }
            }

            Sleep::for(self::PAUSE_MS)->milliseconds();
        }

        return $pages;
    }

    private function parse(string $html, string $id, string $url): ?array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);

        $event = $xpath->query('//*[contains(@itemtype, "schema.org/Event")]')->item(0);

        if (! $event instanceof DOMElement) {
            return null;
        }

        // Scripts, forms (which carry one-off tokens) and rotating advertisements are not the event.
        foreach (iterator_to_array($xpath->query('.//script|.//style|.//noscript|.//form|.//iframe|.//*[contains(@class, "gz-banner")]|.//*[contains(@class, "mn-banner")]', $event)) as $node) {
            $node->parentNode?->removeChild($node);
        }

        $title = PlainText::line($this->text($xpath, './/*[@itemprop="name" and not(ancestor::*[@itemprop="location"])]', $event));
        $start = $this->time($this->attribute($xpath, './/*[@itemprop="startDate"]', 'content', $event));

        if ($title === null || $start === null) {
            return null;
        }

        [$startAt, $startHasTime] = $start;
        $end = $this->time($this->attribute($xpath, './/*[@itemprop="endDate"]', 'content', $event));

        return [
            'external_id' => $id,
            'title' => $title,
            'description' => $this->description($xpath, $event, $document),
            'start_at' => $startAt,
            'end_at' => $end[0] ?? null,
            // An all-day entry has no time line and runs from midnight.
            'all_day' => ! $startHasTime
                || ($xpath->query('.//*[contains(@class, "gz-details-time")]', $event)->length === 0 && $startAt->format('H:i') === '00:00'),
            'location' => $this->location($xpath, $event, $document),
            'url' => $url,
            'timezone' => self::TIMEZONE,
            'raw' => trim(preg_replace('/\n\s*\n+/', "\n", (string) $document->saveHTML($event))),
        ];
    }

    private function text(DOMXPath $xpath, string $query, DOMElement $context): ?string
    {
        return $xpath->query($query, $context)->item(0)?->textContent;
    }

    private function attribute(DOMXPath $xpath, string $query, string $name, DOMElement $context): ?string
    {
        $node = $xpath->query($query, $context)->item(0);

        return $node instanceof DOMElement ? ($node->getAttribute($name) ?: null) : null;
    }

    /** @return array{0: Carbon, 1: bool}|null the moment in regional time, and whether a time of day was given */
    private function time(?string $value): ?array
    {
        if ($value === null || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return null;
        }

        $hasTime = str_contains($value, 'T');

        return [$hasTime ? Carbon::parse($value)->setTimezone(self::TIMEZONE) : Carbon::parse($value, self::TIMEZONE), $hasTime];
    }

    private function description(DOMXPath $xpath, DOMElement $event, DOMDocument $document): ?string
    {
        $about = $xpath->query('.//*[@itemprop="about" or @itemprop="description"]', $event)->item(0);

        if (! $about instanceof DOMElement) {
            return null;
        }

        $html = '';

        // The section's own "Description" heading is the page's, not the event's.
        foreach ($xpath->query('.//*[contains(@class, "gz-subtitle")]', $about) as $heading) {
            $heading->parentNode?->removeChild($heading);
        }

        foreach ($about->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }

        return PlainText::clean($html);
    }

    /** The place as one line: the venue's own wording, otherwise the marked-up address. */
    private function location(DOMXPath $xpath, DOMElement $event, DOMDocument $document): ?string
    {
        $place = $xpath->query('.//*[@itemprop="location"]', $event)->item(0);

        if (! $place instanceof DOMElement) {
            return null;
        }

        $name = $xpath->query('./descendant::*[@itemprop="name"][1]', $place)->item(0);
        $text = $name ? PlainText::clean((string) $document->saveHTML($name)) : null;
        $lines = $text === null ? [] : array_values(array_filter(array_map(fn ($line) => trim($line, " \t,"), explode("\n", $text))));

        $part = fn (string $property) => PlainText::line($this->text($xpath, './/*[@itemprop="'.$property.'"]', $place));
        $street = $part('streetAddress');

        // Add the marked-up address only when the venue's wording does not already give it.
        if ($street !== null && ! str_contains(mb_strtolower(implode(' ', $lines)), mb_strtolower(rtrim($street, '.')))) {
            $region = trim(($part('addressRegion') ?? '').' '.($part('postalCode') ?? ''));
            $lines = array_merge($lines, array_filter([$street, $part('addressLocality'), $region]));
        }

        $line = trim(preg_replace('/\s+/u', ' ', implode(', ', $lines)), ' ,');

        return $line === '' ? null : mb_substr($line, 0, 250);
    }
}
