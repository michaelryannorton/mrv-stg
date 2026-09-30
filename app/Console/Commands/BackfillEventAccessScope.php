<?php

namespace App\Console\Commands;

use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill for events created before events.access_scope existed. Every event created
 * from here on gets access_scope set at creation by EventIngestor (from Source::$access_scope);
 * this fills in the ones ingested earlier — see logs/2026 0929 2130 Event-Level Access-Scope
 * Classification.md. Not source-specific: derives each event's value from whichever source it's
 * linked to via event_sources, so it applies to every collector (ICS and Tribe alike), not just
 * the 25 Tribe sources. Safe to re-run — only touches events still at the column's 'unknown'
 * default with no access_scope set of their own.
 */
class BackfillEventAccessScope extends Command
{
    protected $signature = 'backfill:event-access-scope';

    protected $description = "Set events.access_scope from each event's linked source, for events created before the column existed";

    public function handle(): int
    {
        $eventIdToScope = DB::table('event_sources')
            ->join('sources', 'event_sources.source_id', '=', 'sources.id')
            ->where('sources.access_scope', '!=', 'unknown')
            ->pluck('sources.access_scope', 'event_sources.event_id');

        $updated = 0;

        foreach ($eventIdToScope as $eventId => $accessScope) {
            $affected = Event::where('id', $eventId)
                ->where('access_scope', 'unknown')
                ->update(['access_scope' => $accessScope]);

            $updated += $affected;
        }

        $this->info("Updated access_scope on {$updated} events from their source's classification.");

        return self::SUCCESS;
    }
}
