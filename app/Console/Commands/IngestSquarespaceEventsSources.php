<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Services\Ingestion\EventIngestor;
use App\Services\Ingestion\SquarespaceEventsCollector;
use Illuminate\Console\Command;
use Throwable;

class IngestSquarespaceEventsSources extends Command
{
    protected $signature = 'ingest:squarespace-events {source? : Only run this source ID; otherwise every active Squarespace events source}';

    protected $description = 'Fetch active Squarespace events-collection sources and create/update candidate events';

    public function handle(SquarespaceEventsCollector $collector, EventIngestor $ingestor): int
    {
        $sources = Source::query()
            ->where('collector_type', 'squarespace_events')
            ->where('active', true)
            ->when($this->argument('source'), fn ($q, $id) => $q->where('id', $id))
            ->get();

        if ($sources->isEmpty()) {
            $this->info('No active Squarespace events sources to process.');

            return self::SUCCESS;
        }

        foreach ($sources as $source) {
            $this->processSource($source, $collector, $ingestor);
        }

        return self::SUCCESS;
    }

    private function processSource(Source $source, SquarespaceEventsCollector $collector, EventIngestor $ingestor): void
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

        $result = $ingestor->ingest($source, $items);

        $this->info("  {$source->name}: {$result['created']} created, {$result['updated']} updated.");
    }
}
