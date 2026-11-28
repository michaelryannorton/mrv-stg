<?php

namespace App\Services\Ingestion;

use App\Models\Source;
use RuntimeException;
use Throwable;

/**
 * Fetches one source with the collector for its type, keeps the feed's responses, records success or
 * failure on the source, and ingests the items. Every ingest command goes through this.
 */
class FeedRunner
{
    /** Collector by the source's collector_type. */
    public const COLLECTORS = [
        'ics' => IcsCollector::class,
        'tribe_events' => TribeEventsCollector::class,
        'squarespace_events' => SquarespaceEventsCollector::class,
        'growthzone' => GrowthZoneCollector::class,
    ];

    public function __construct(private EventIngestor $ingestor) {}

    /**
     * @return array{ok: bool, created: int, updated: int, error: ?string}
     */
    public function run(Source $source): array
    {
        $source->last_checked_at = now();
        $archive = new FeedArchive;

        try {
            $class = self::COLLECTORS[$source->collector_type]
                ?? throw new RuntimeException("No collector for type \"{$source->collector_type}\"");
            $items = app($class)->fetch($source->feed_url, 180, $archive);
        } catch (Throwable $e) {
            $source->last_error = $e->getMessage();
            $source->save();

            return ['ok' => false, 'created' => 0, 'updated' => 0, 'error' => $e->getMessage()];
        }

        $source->last_success_at = now();
        $source->last_error = null;
        $source->save();

        $archive->commit($source);

        return ['ok' => true, 'error' => null, ...$this->ingestor->ingest($source, $items)];
    }
}
