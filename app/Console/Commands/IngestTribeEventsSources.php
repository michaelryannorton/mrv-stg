<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Services\Ingestion\EventIngestor;
use App\Services\Ingestion\TribeEventsCollector;
use Illuminate\Console\Command;
use Throwable;

class IngestTribeEventsSources extends Command
{
    protected $signature = 'ingest:tribe-events {source? : Only run this source ID; otherwise every active Tribe Events source}';

    protected $description = 'Fetch active WordPress "The Events Calendar" (Tribe Events) sources and create/update candidate events';

    public function handle(TribeEventsCollector $collector, EventIngestor $ingestor): int
    {
        $sources = Source::query()
            ->where('collector_type', 'tribe_events')
            ->where('active', true)
            ->when($this->argument('source'), fn ($q, $id) => $q->where('id', $id))
            ->get();

        if ($sources->isEmpty()) {
            $this->info('No active Tribe Events sources to process.');

            return self::SUCCESS;
        }

        foreach ($sources as $source) {
            $this->processSource($source, $collector, $ingestor);
        }

        return self::SUCCESS;
    }

    private function processSource(Source $source, TribeEventsCollector $collector, EventIngestor $ingestor): void
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
