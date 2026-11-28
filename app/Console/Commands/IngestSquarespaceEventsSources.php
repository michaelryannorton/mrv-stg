<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsFeeds;
use App\Models\Source;
use App\Services\Ingestion\FeedRunner;
use Illuminate\Console\Command;

class IngestSquarespaceEventsSources extends Command
{
    use RunsFeeds;

    protected $signature = 'ingest:squarespace-events {source? : Only run this source ID; otherwise every active Squarespace events source}';

    protected $description = 'Fetch active Squarespace events-collection sources and create/update candidate events';

    public function handle(FeedRunner $runner): int
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

        return $this->runFeeds($sources, $runner);
    }
}
