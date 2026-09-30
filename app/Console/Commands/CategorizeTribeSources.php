<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\Source;
use Illuminate\Console\Command;

/**
 * Fixes a real gap found while re-running the Coverage Gap Audit against real event volume (see
 * logs/2026 0929 1015 Coverage Gap Audit Re-Run Against Real Event Volume.md): none of the 213
 * imported corpus sources ever had a `source_default_categories` row, so every event
 * `TribeEventsCollector`/`EventIngestor` created — all 2,213 of them — carries zero categories and
 * is invisible to the public calendar's category filter. This assigns sensible default categories
 * to the 25 active Tribe sources (from `Category`'s existing taxonomy, not a new one) and backfills
 * every already-created event that still has none. Idempotent: `sync()` on the pivot, and only
 * events with zero categories are touched, so it never overwrites a category a human later set by
 * hand via the admin editor.
 */
class CategorizeTribeSources extends Command
{
    protected $signature = 'categorize:tribe-sources';

    protected $description = 'Assign default categories to the 25 active Tribe Events sources and backfill already-created events that have none';

    /** Legacy source ID => category names, chosen from the app's existing taxonomy based on each source's own "Coverage"/"Event Types" text in the corpus. */
    private const CATEGORY_MAP = [
        'SRC-0002' => ['Outdoor Recreation', 'Family Activities'],   // Hesperia Recreation & Park District
        'SRC-0008' => ['Outdoor Recreation', 'Family Activities'],   // SB County Regional Parks
        'SRC-0009' => ['Art', 'Family Activities'],                  // Victor Valley Museum
        'SRC-0010' => ['Books', 'Family Activities'],                // SB County Library
        'SRC-0067' => ['Local Government'],                          // First District Venue/Event Pages
        'SRC-0073' => ['Local Government'],                          // Workforce Development
        'SRC-0074' => ['Local Government'],                         // Public Defender
        'SRC-0078' => ['Food', 'Local Government'],                  // Environmental Health Farmers Markets
        'SRC-0079' => ['Local Government', 'Family Activities'],     // Our-HD High Desert Events
        'SRC-0128' => ['Local Government'],                          // Behavioral Health
        'SRC-0129' => ['Local Government'],                          // Public Health
        'SRC-0130' => ['Local Government'],                          // Airports
        'SRC-0132' => ['Local Government', 'Food'],                  // First District Events Calendar
        'SRC-0133' => ['Art', 'Family Activities'],                  // Museum Master Events Calendar
        'SRC-0135' => ['Local Government'],                          // Animal Care
        'SRC-0136' => ['Local Government'],                          // Human Resources
        'SRC-0137' => ['Local Government'],                          // Sheriff's Jobs
        'SRC-0138' => ['Local Government'],                          // Child Support Services
        'SRC-0142' => ['Local Government'],                          // Fire
        'SRC-0143' => ['Local Government'],                          // Transitional Assistance
        'SRC-0146' => ['Local Government'],                          // Aging & Adult Services
        'SRC-0147' => ['Local Government', 'Food'],                  // Third District Events & Community Calendar
        'SRC-0149' => ['Local Government', 'Family Activities'],     // Children's Network
        'SRC-0150' => ['Local Government'],                          // Homeless Partnership
        'SRC-0190' => ['Outdoor Recreation', 'Family Activities'],   // Lucerne Valley / CSA 29
    ];

    public function handle(): int
    {
        $categorized = 0;
        $backfilled = 0;

        foreach (self::CATEGORY_MAP as $legacyId => $categoryNames) {
            $source = Source::where('legacy_source_id', $legacyId)->first();

            if (! $source) {
                $this->warn("Missing source: {$legacyId}");

                continue;
            }

            $categoryIds = Category::whereIn('name', $categoryNames)->pluck('id');

            if ($categoryIds->count() !== count($categoryNames)) {
                $this->warn("Category name mismatch for {$legacyId}: expected ".implode(', ', $categoryNames));
            }

            $source->defaultCategories()->sync($categoryIds);
            $categorized++;

            $eventIds = EventSource::where('source_id', $source->id)->pluck('event_id');

            $uncategorizedEventIds = Event::whereIn('id', $eventIds)
                ->whereDoesntHave('categories')
                ->pluck('id');

            foreach ($uncategorizedEventIds as $eventId) {
                Event::find($eventId)->categories()->sync($categoryIds);
                $backfilled++;
            }
        }

        $this->info("Assigned default categories to {$categorized} sources.");
        $this->info("Backfilled categories on {$backfilled} previously-uncategorized events.");

        return self::SUCCESS;
    }
}
