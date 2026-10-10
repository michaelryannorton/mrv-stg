<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\EventSource;
use App\Support\ExternalUrl;
use Illuminate\Console\Command;

/**
 * One-time backfill for addresses stored before ingestion cleaned them: some feeds (the
 * ActiveCommunities calendars, for one) HTML-escape the URL field, which left "&amp;" inside
 * stored query strings. New values are cleaned on the way in by EventIngestor and the Event
 * model; this repairs the rows already stored. Safe to re-run: it only touches values that
 * change. An event whose canonical_url was hand-edited (locked in overridden_fields) is left
 * alone and counted. source_records are untouched on purpose: they are the immutable record of
 * what each feed said on each run.
 */
class CleanEventUrls extends Command
{
    protected $signature = 'clean:event-urls {--dry-run : Report what would change without writing}';

    protected $description = 'Decode HTML-escaped characters in stored event and event-source addresses';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $events = 0;
        $locked = 0;
        $sources = 0;

        Event::withTrashed()->where('canonical_url', 'like', '%&%')->chunkById(200, function ($chunk) use ($dryRun, &$events, &$locked) {
            foreach ($chunk as $event) {
                if (in_array('canonical_url', $event->overridden_fields ?? [], true)) {
                    $locked++;

                    continue;
                }

                if (ExternalUrl::clean($event->canonical_url) === $event->canonical_url) {
                    continue;
                }

                $events++;

                if (! $dryRun) {
                    Event::withoutTimestamps(fn () => $event->update(['canonical_url' => ExternalUrl::clean($event->canonical_url)]));
                }
            }
        });

        EventSource::where('source_url', 'like', '%&%')->chunkById(200, function ($chunk) use ($dryRun, &$sources) {
            foreach ($chunk as $link) {
                $clean = ExternalUrl::clean($link->source_url);

                if ($clean === $link->source_url) {
                    continue;
                }

                $sources++;

                if (! $dryRun) {
                    $link->update(['source_url' => $clean]);
                }
            }
        });

        $verb = $dryRun ? 'Would clean' : 'Cleaned';
        $this->info("{$verb} {$events} event addresses and {$sources} event-source addresses; skipped {$locked} hand-edited event addresses.");

        return self::SUCCESS;
    }
}
