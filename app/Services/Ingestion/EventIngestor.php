<?php

namespace App\Services\Ingestion;

use App\Models\Event;
use App\Models\EventSource;
use App\Models\Source;
use App\Models\SourceRecord;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Collector-agnostic create/update pass: takes a source's already-fetched, normalized
 * occurrences (the shape every collector's fetch() returns — external_id/title/description/
 * start_at/end_at/all_day/location/url/timezone) and applies the same moderation-gate,
 * override-protection, and drift-detection logic every ingestion source needs. Extracted from
 * IngestIcsSources so a second collector (Tribe Events) doesn't duplicate this — the locking/
 * staleness behavior is the one part of this pipeline that must never drift between collectors.
 */
class EventIngestor
{
    /**
     * @param  list<array{external_id: string, title: string, description: ?string,
     *     start_at: Carbon, end_at: ?Carbon, all_day: bool, location: ?string,
     *     url: ?string, timezone: string}>  $items
     * @return array{created: int, updated: int}
     */
    public function ingest(Source $source, array $items): array
    {
        $created = 0;
        $updated = 0;
        $defaultCategoryIds = $source->defaultCategories->pluck('id');
        $defaultTagIds = $source->defaultTags->pluck('id');

        foreach ($items as $item) {
            $record = SourceRecord::create([
                'source_id' => $source->id,
                'external_id' => $item['external_id'],
                'source_url' => $item['url'] ?? $source->feed_url,
                'record_type' => 'event',
                'raw_payload' => [
                    ...$item,
                    'start_at' => $item['start_at']->toIso8601String(),
                    'end_at' => $item['end_at']?->toIso8601String(),
                ],
                'observed_at' => now(),
                'processed_at' => now(),
                'processing_status' => 'processed',
            ]);

            $link = EventSource::where('source_id', $source->id)
                ->where('external_id', $item['external_id'])
                ->first();

            if ($link) {
                $freshValues = [
                    'title' => $item['title'],
                    'description' => $item['description'],
                    'start_at' => $item['start_at'],
                    'end_at' => $item['end_at'],
                    'all_day' => $item['all_day'],
                    'timezone' => $item['timezone'],
                    'location_name_override' => $item['location'],
                    'canonical_url' => $item['url'],
                    'organizer_id' => $source->organization_id,
                ];

                // Fields Michael has hand-edited via the event editor are locked against being
                // silently overwritten by the next scheduled re-sync — see Event::INGESTED_FIELDS.
                $event = $link->event;
                $lockedFields = $event->overridden_fields ?? [];
                $writableValues = array_diff_key($freshValues, array_flip($lockedFields));

                $driftedFields = array_values(array_filter(
                    $lockedFields,
                    fn (string $field) => array_key_exists($field, $freshValues)
                        && Event::valuesDiffer($event->getAttribute($field), $freshValues[$field]),
                ));

                $writableValues['stale_fields'] = $driftedFields ?: null;

                $event->update($writableValues);
                $link->update(['last_seen_at' => now(), 'source_record_id' => $record->id]);
                $updated++;

                continue;
            }

            // Nothing goes public until reviewed, unless this specific source has earned that
            // trust — see community/Community Events Calendar - Phase 1 Plan.md section 4.
            $autoPublish = $source->autoPublishes();

            $event = Event::create([
                'title' => $item['title'],
                'slug' => Str::slug($item['title']).'-'.Str::random(6),
                'description' => $item['description'],
                'start_at' => $item['start_at'],
                'end_at' => $item['end_at'],
                'timezone' => $item['timezone'],
                'all_day' => $item['all_day'],
                'canonical_url' => $item['url'],
                'location_name_override' => $item['location'],
                'organizer_id' => $source->organization_id,
                'access_scope' => $source->access_scope,
                'status' => $autoPublish ? 'scheduled' : 'candidate',
                'editorial_status' => $autoPublish ? 'published' : 'pending_review',
            ]);

            $event->categories()->sync($defaultCategoryIds);
            $event->tags()->sync($defaultTagIds);

            EventSource::create([
                'event_id' => $event->id,
                'source_id' => $source->id,
                'source_record_id' => $record->id,
                'external_id' => $item['external_id'],
                'source_url' => $item['url'] ?? $source->feed_url,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'is_primary' => true,
            ]);

            $created++;
        }

        return ['created' => $created, 'updated' => $updated];
    }
}
