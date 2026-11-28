<?php

namespace App\Console\Commands;

use App\Models\EventSource;
use App\Services\Places\FeedFacts;
use App\Services\Places\PlaceAssigner;
use Illuminate\Console\Command;

/**
 * Fills the venue, cost and audience of events collected before those were read, from each one's kept
 * record. Blanks only. A dry run unless --apply is given; safe to run again. Not scheduled.
 */
class FillPlaces extends Command
{
    protected $signature = 'places:fill {--apply : Write the values; without this nothing changes}';

    protected $description = "Fill blank venue, cost and audience on stored events from their feed's kept record";

    public function handle(PlaceAssigner $assigner): int
    {
        $apply = (bool) $this->option('apply');
        $counts = ['events' => 0, 'venue' => 0, 'cost' => 0, 'audiences' => 0];

        $links = EventSource::query()
            ->where('is_primary', true)
            ->with(['event', 'source:id,collector_type', 'sourceRecord'])
            ->lazyById(200);

        foreach ($links as $link) {
            if ($link->event === null || $link->sourceRecord?->raw_text === null) {
                continue;
            }

            $counts['events']++;
            $facts = FeedFacts::from($link->source->collector_type, $link->sourceRecord->raw_text, $link->sourceRecord->raw_payload['location'] ?? null);

            foreach ($assigner->apply($link->event, $facts, null, $apply) as $changed) {
                $counts[$changed]++;
            }
        }

        $this->info(sprintf(
            'Events with a kept record: %d. %s a venue: %d, cost: %d, audiences: %d.%s',
            $counts['events'], $apply ? 'Given' : 'Would be given', $counts['venue'], $counts['cost'], $counts['audiences'],
            $apply ? '' : ' Dry run: nothing changed.',
        ));

        return self::SUCCESS;
    }
}
