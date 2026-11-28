<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsFeeds;
use App\Models\Source;
use App\Services\Ingestion\FeedRunner;
use Illuminate\Console\Command;

/**
 * The feeds marked to be collected on a schedule (collector_config "scheduled"), other than calendar
 * (ICS) feeds, which ingest:ics already collects every 30 minutes. Being active is not enough: the
 * database holds active sources nobody has decided to collect.
 */
class IngestScheduledFeeds extends Command
{
    use RunsFeeds;

    protected $signature = 'ingest:feeds';

    protected $description = 'Collect every active feed marked for scheduled collection (not ICS)';

    public function handle(FeedRunner $runner): int
    {
        $sources = Source::scheduled()->where('collector_type', '!=', 'ics')->orderBy('id')->get();

        if ($sources->isEmpty()) {
            $this->info('No feeds are marked for scheduled collection.');

            return self::SUCCESS;
        }

        return $this->runFeeds($sources, $runner);
    }
}
