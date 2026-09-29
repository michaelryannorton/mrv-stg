<?php

namespace App\Services\Ingestion;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * WordPress "The Events Calendar" (Tribe Events) plugin's own public REST API — confirmed
 * present on 25 of the 32 Victor Valley corpus sources tagged as this platform family (see
 * logs/2026 0928 2008 Tribe Events Endpoint Discovery Pass.md). No auth, no per-source setup
 * beyond the confirmed feed_url — the endpoint shape is identical across every install.
 */
class TribeEventsCollector
{
    /**
     * @return list<array{external_id: string, title: string, description: ?string,
     *     start_at: Carbon, end_at: ?Carbon, all_day: bool, location: ?string, url: ?string,
     *     timezone: string}>
     */
    public function fetch(string $feedUrl, int $windowDays = 180): array
    {
        $startDate = now()->toDateString();
        $endDate = now()->addDays($windowDays)->toDateString();

        $occurrences = [];
        $page = 1;
        $totalPages = 1;

        do {
            $response = Http::timeout(20)->get($feedUrl, [
                'page' => $page,
                'per_page' => 50,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => 'publish',
            ]);

            if (! $response->successful()) {
                throw new RuntimeException("Tribe Events fetch failed with HTTP {$response->status()}");
            }

            $payload = $response->json() ?? [];

            foreach ($payload['events'] ?? [] as $event) {
                $normalized = $this->normalize($event);

                if ($normalized !== null) {
                    $occurrences[] = $normalized;
                }
            }

            $totalPages = (int) ($payload['total_pages'] ?? 1);
            $page++;
        } while ($page <= $totalPages);

        return $occurrences;
    }

    private function normalize(array $event): ?array
    {
        if (empty($event['start_date']) || empty($event['id'])) {
            return null;
        }

        // Same reasoning as IcsCollector: a source that doesn't tell us a real IANA zone means
        // "assume regional local time," never UTC, on this hyper-regional calendar. Several
        // county WordPress installs have their site timezone manually set to a static "UTC-7"/
        // "UTC+0"-style offset instead of picking "America/Los Angeles" from the dropdown —
        // Carbon rejects that string outright, and every one of these sources is Pacific time
        // regardless, so any non-IANA-looking value falls back the same way plain "UTC" does.
        $timezone = $event['timezone'] ?? null;
        if (! $timezone || ! str_contains($timezone, '/')) {
            $timezone = 'America/Los_Angeles';
        }

        $startAt = Carbon::parse($event['start_date'], $timezone);
        $endAt = ! empty($event['end_date']) ? Carbon::parse($event['end_date'], $timezone) : null;

        $venueName = $event['venue']['venue'] ?? null;
        $url = $event['website'] ?: ($event['url'] ?? null);

        return [
            'external_id' => (string) $event['id'],
            'title' => $this->cleanText($event['title'] ?? null) ?? 'Untitled event',
            'description' => $this->cleanText($event['description'] ?? null),
            'start_at' => $startAt,
            'end_at' => $endAt,
            'all_day' => (bool) ($event['all_day'] ?? false),
            'location' => $this->cleanText($venueName),
            'url' => $url,
            'timezone' => $timezone,
        ];
    }

    private function cleanText(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $clean = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5));

        return $clean === '' ? null : $clean;
    }
}
