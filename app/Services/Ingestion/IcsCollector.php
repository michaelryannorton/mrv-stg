<?php

namespace App\Services\Ingestion;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VEvent;
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
     *     timezone: string, raw: string, raw_stable: string}>
     */
    public function fetch(string $feedUrl, int $expandWindowDays = 180, ?FeedArchive $archive = null): array
    {
        $response = Http::timeout(20)->get($feedUrl);

        if (! $response->successful()) {
            throw new RuntimeException("ICS fetch failed with HTTP {$response->status()}");
        }

        $archive?->add('calendar', $response->body());
        $calendar = Reader::read($response->body());
        // Taken before expansion, which drops the recurrence rule and other parts of the original.
        $originals = $this->originals($calendar);

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
                $raw = $this->raw($originals, $vevent);
                $normalized['raw'] = $raw;
                // Google writes the time of the download into DTSTAMP, which is not a change.
                $normalized['raw_stable'] = preg_replace('/^DTSTAMP[;:][^\r\n]*\r?\n/m', '', $raw);
                $occurrences[] = $normalized;
            }
        }

        return $occurrences;
    }

    /**
     * The feed's own VEVENTs by UID: the series (or single event) and its changed occurrences.
     *
     * @return array<string, array{main: ?string, changed: array<int, string>}>
     */
    private function originals(Component $calendar): array
    {
        $originals = [];

        foreach ($calendar->select('VEVENT') as $vevent) {
            $uid = (string) $vevent->UID;
            $originals[$uid] ??= ['main' => null, 'changed' => []];

            if (isset($vevent->{'RECURRENCE-ID'})) {
                $originals[$uid]['changed'][$vevent->{'RECURRENCE-ID'}->getDateTime()->getTimestamp()] = $vevent->serialize();
            } else {
                $originals[$uid]['main'] = $vevent->serialize();
            }
        }

        return $originals;
    }

    /** What the feed sent for one occurrence: its series, and the change to this date if there is one. */
    private function raw(array $originals, VEvent $occurrence): string
    {
        $original = $originals[(string) $occurrence->UID] ?? ['main' => null, 'changed' => []];
        $at = isset($occurrence->{'RECURRENCE-ID'}) ? $occurrence->{'RECURRENCE-ID'}->getDateTime()->getTimestamp() : null;
        $raw = ($original['main'] ?? '').($at !== null ? ($original['changed'][$at] ?? '') : '');

        return $raw !== '' ? $raw : $occurrence->serialize();
    }

    private function normalize(VEvent $vevent): ?array
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

        // This is a hyper-regional calendar — nobody wants times displayed in UTC, ever. A
        // floating/unspecified-timezone time reads as UTC by PHP/sabre default, and some source
        // feeds (Startup Mojave's included) encode their local time with a literal Z suffix
        // instead of their real IANA zone — both cases mean "this source didn't tell us a real
        // timezone," so both fall back to the region's own timezone for display. The stored
        // instant (start_at) is unaffected either way; only the display timezone changes.
        $timezoneName = $startDateTime->getTimezone()->getName();
        $timezone = $timezoneName === 'UTC' ? 'America/Los_Angeles' : $timezoneName;

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
}
