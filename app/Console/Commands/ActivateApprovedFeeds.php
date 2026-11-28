<?php

namespace App\Console\Commands;

use App\Models\Source;
use Illuminate\Console\Command;

/**
 * The feeds Michael approved on 2026-11-27 for collection on a schedule: two chambers of commerce,
 * the county library and the county museum
 * (logs/2026 1127 2039 Feed Candidates, And Plan Addition For Automatic Coordinates And Live Distance.md).
 * The museum is the master calendar (SRC-0133); SRC-0009 has the same feed address and is left alone.
 * Changes only these sources; safe to run again.
 */
class ActivateApprovedFeeds extends Command
{
    protected $signature = 'activate:approved-feeds';

    protected $description = 'Set up the four approved feeds and mark them for scheduled collection';

    /** Legacy source ID => collector, kind of source and feed address. */
    private const FEEDS = [
        'SRC-0010' => ['tribe_events', 'api', 'https://library.sbcounty.gov/wp-json/tribe/events/v1/events'],
        'SRC-0017' => ['growthzone', 'web', 'https://members.ghdcc.com/events'],
        'SRC-0091' => ['tribe_events', 'api', 'https://hdhcc.org/wp-json/tribe/events/v1/events'],
        'SRC-0133' => ['tribe_events', 'api', 'https://museum.sbcounty.gov/wp-json/tribe/events/v1/events'],
    ];

    public function handle(): int
    {
        $set = 0;

        foreach (self::FEEDS as $legacyId => [$collector, $type, $feed]) {
            $source = Source::where('legacy_source_id', $legacyId)->first();

            if (! $source) {
                $this->warn("Missing source: {$legacyId}");

                continue;
            }

            // Each active feed needs a web page: events without their own address link to it.
            if ($source->webPage() === null) {
                $this->warn("{$legacyId}: no web page (base_url); skipping.");

                continue;
            }

            $source->update([
                'collector_type' => $collector,
                'source_type' => $type,
                'feed_url' => $feed,
                'active' => true,
                'collector_config' => [...($source->collector_config ?? []), 'scheduled' => true],
            ]);
            $this->info("{$legacyId} (source {$source->id}) {$source->name}: set up.");
            $set++;
        }

        $this->info("{$set} of ".count(self::FEEDS).' feeds set up.');

        return $set === count(self::FEEDS) ? self::SUCCESS : self::FAILURE;
    }
}
