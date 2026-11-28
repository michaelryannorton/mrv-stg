<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsFeeds;
use App\Models\Source;
use App\Services\Ingestion\FeedRunner;
use Illuminate\Console\Command;

class IngestIcsSources extends Command
{
    use RunsFeeds;

    protected $signature = 'ingest:ics {source? : Only run this source ID; otherwise every active ICS source}';

    protected $description = 'Fetch active ICS calendar sources and create/update candidate events';

    public function handle(FeedRunner $runner): int
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

        return $this->runFeeds($sources, $runner);
    }
}
