<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsFeeds;
use App\Models\Source;
use App\Services\Ingestion\FeedRunner;
use Illuminate\Console\Command;

class IngestTribeEventsSources extends Command
{
    use RunsFeeds;

    protected $signature = 'ingest:tribe-events {source? : Only run this source ID; otherwise every active Tribe Events source}';

    protected $description = 'Fetch active WordPress "The Events Calendar" (Tribe Events) sources and create/update candidate events';

    public function handle(FeedRunner $runner): int
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

        return $this->runFeeds($sources, $runner);
    }
}
