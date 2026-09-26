<?php

namespace App\Services\Ingestion;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Sabre\VObject\Reader;

class IcsCollector
{
    /**
     * Fetch and normalize every event occurrence from an ICS feed. Recurring events (RRULE) are
     * expanded into individual concrete occurrences via sabre/vobject's own expand() — per
     * community/reference/Community Information Platform - Database Schema section 106, actual
     * occurrences should become real event rows, not a stored recurrence rule the app has to
     * re-derive later.
     *
     * @return list<array{external_id: string, title: string, description: ?string,
     *     start_at: Carbon, end_at: ?Carbon, all_day: bool, location: ?string, url: ?string,
     *     timezone: string}>
     */
    public function fetch(string $feedUrl, int $expandWindowDays = 180): array
    {
        $response = Http::timeout(20)->get($feedUrl);

        if (! $response->successful()) {
            throw new RuntimeException("ICS fetch failed with HTTP {$response->status()}");
        }

        $calendar = Reader::read($response->body());

        // expand() replaces RRULE-based events with one concrete VEVENT per occurrence in this
        // window, each carrying its own DTSTART and a RECURRENCE-ID distinguishing it from its
        // siblings — this is also safe to call on calendars with no recurring events at all.
        $calendar = $calendar->expand(
            new \DateTimeImmutable('now'),
            new \DateTimeImmutable("+{$expandWindowDays} days"),
        );

        $occurrences = [];

        foreach ($calendar->select('VEVENT') as $vevent) {
            $normalized = $this->normalize($vevent);

            if ($normalized !== null) {
                $occurrences[] = $normalized;
            }
        }

        return $occurrences;
    }

    private function normalize(\Sabre\VObject\Component\VEvent $vevent): ?array
    {
        if (! isset($vevent->DTSTART)) {
            return null;
        }

        $startDateTime = $vevent->DTSTART->getDateTime();
        $allDay = ! $vevent->DTSTART->hasTime();

        $uid = (string) $vevent->UID;
        $recurrenceId = isset($vevent->{'RECURRENCE-ID'}) ? $vevent->{'RECURRENCE-ID'}->getDateTime()->format('Ymd\THis') : null;

        // A bare UID is only unique per recurring *series*; distinguish individual occurrences the
        // same way the ICS itself does, via RECURRENCE-ID, so they don't collide in event_sources'
        // (source_id, external_id) uniqueness constraint.
        $externalId = $recurrenceId ? "{$uid}-{$recurrenceId}" : $uid;

        // A genuine floating/unspecified-timezone time is rare in real venue feeds; when it happens,
        // assume the region's own timezone rather than whatever PHP's default happens to be.
        $timezoneName = $startDateTime->getTimezone()->getName();
        $timezone = ($timezoneName === 'UTC' && ! $this->explicitlyUtc($vevent)) ? 'America/Los_Angeles' : $timezoneName;

        return [
            'external_id' => $externalId,
            'title' => isset($vevent->SUMMARY) ? (string) $vevent->SUMMARY : 'Untitled event',
            'description' => isset($vevent->DESCRIPTION) ? (string) $vevent->DESCRIPTION : null,
            'start_at' => Carbon::instance($startDateTime),
            'end_at' => isset($vevent->DTEND) ? Carbon::instance($vevent->DTEND->getDateTime()) : null,
            'all_day' => $allDay,
            'location' => isset($vevent->LOCATION) ? (string) $vevent->LOCATION : null,
            'url' => isset($vevent->URL) ? (string) $vevent->URL : null,
            'timezone' => $timezone,
        ];
    }

    /**
     * DTSTART with a literal Z suffix (e.g. 20260101T190000Z) is genuinely, explicitly UTC — as
     * opposed to a value with no timezone information at all, which PHP/sabre also reports as UTC
     * but by default rather than by the source's actual intent.
     */
    private function explicitlyUtc(\Sabre\VObject\Component\VEvent $vevent): bool
    {
        return str_ends_with((string) $vevent->DTSTART, 'Z');
    }
}
