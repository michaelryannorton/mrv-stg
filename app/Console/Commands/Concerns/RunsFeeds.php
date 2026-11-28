<?php

namespace App\Console\Commands\Concerns;

use App\Models\Source;
use App\Services\Ingestion\FeedRunner;
use Illuminate\Support\Collection;

trait RunsFeeds
{
    /** @param Collection<int, Source> $sources */
    private function runFeeds(Collection $sources, FeedRunner $runner): int
    {
        foreach ($sources as $source) {
            $this->info("Fetching {$source->name}...");
            $result = $runner->run($source);

            $result['ok']
                ? $this->info("  {$source->name}: {$result['created']} created, {$result['updated']} updated.")
                : $this->error("  Failed: {$result['error']}");
        }

        return self::SUCCESS;
    }
}
