<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\EventSource;
use App\Models\Source;
use App\Models\SourceRecord;
use App\Services\Ingestion\IcsCollector;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class IngestIcsSources extends Command
{
    protected $signature = 'ingest:ics {source? : Only run this source ID; otherwise every active ICS source}';

    protected $description = 'Fetch active ICS calendar sources and create/update candidate events';

    public function handle(IcsCollector $collector): int
    {
        $sources = Source::query()
            ->where('collector_type', 'ics')
            ->where('active', true)
            ->when($this->argument('source'), fn ($q, $id) => $q->where('id', $id))
            ->get();

        if ($sources->isEmpty()) {
            $this->info('No active ICS sources to process.');

            return self::SUCCESS;
        }

        foreach ($sources as $source) {
            $this->processSource($source, $collector);
        }

        return self::SUCCESS;
    }

    private function processSource(Source $source, IcsCollector $collector): void
    {
        $this->info("Fetching {$source->name}...");
        $source->last_checked_at = now();

        try {
            $items = $collector->fetch($source->feed_url);
        } catch (Throwable $e) {
            $source->last_error = $e->getMessage();
            $source->save();
            $this->error("  Failed: {$e->getMessage()}");

            return;
        }

        $source->last_success_at = now();
        $source->last_error = null;
        $source->save();

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
                // silently overwritten by the next scheduled re-sync — see Event::INGESTED_FIELDS
                // and community/Community Events Calendar - Phase 1 Plan.md's build-status section.
                $event = $link->event;
                $lockedFields = $event->overridden_fields ?? [];
                $writableValues = array_diff_key($freshValues, array_flip($lockedFields));

                // Among the locked fields, note which ones the source has actually moved on since
                // being locked — a curator needs to know their edit is now diverging from upstream,
                // even though it's correctly not being overwritten. Recomputed fresh every sync
                // rather than accumulated, so a field stops being flagged if the source settles back
                // to what's already stored.
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

            // Nothing goes public until reviewed, unless this specific source has earned that trust
            // — see community/Community Events Calendar - Phase 1 Plan.md section 4.
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

        $this->info("  {$source->name}: {$created} created, {$updated} updated.");
    }
}
