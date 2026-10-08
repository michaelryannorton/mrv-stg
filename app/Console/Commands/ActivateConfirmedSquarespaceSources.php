<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Source;
use Illuminate\Console\Command;

/**
 * Captures the 2026-10-08 finding that Wrightwood Arts Center's events page is a Squarespace
 * events collection (see logs/2026 1008 1645 Second Collector Discovery, Approval Warning, And
 * Unresolved Sources.md) as a real, idempotent command, so staging can replay what local dev did
 * by hand. Add a row to CONFIRMED_SOURCES for each further Squarespace events page that is
 * confirmed to return an `upcoming` list from `?format=json`. Safe to re-run: every write is an
 * update or a sync.
 */
class ActivateConfirmedSquarespaceSources extends Command
{
    protected $signature = 'activate:confirmed-squarespace-sources';

    protected $description = 'Apply confirmed Squarespace events-collection feeds (feed_url, source_type, collector_type, active) and default categories';

    /**
     * Legacy source ID => events collection page URL (no format parameter) and the default
     * categories for its events. Categories are a judgment call per source, like
     * CategorizeTribeSources, so they live here rather than being derived.
     *
     * @var array<string, array{feed_url: string, categories: list<string>}>
     */
    private const CONFIRMED_SOURCES = [
        // Wrightwood Arts Center: art classes, workshops, exhibit receptions.
        'SRC-0182' => ['feed_url' => 'https://www.wrightwoodarts.org/events', 'categories' => ['Art', 'Crafts']],
    ];

    public function handle(): int
    {
        $activated = 0;

        foreach (self::CONFIRMED_SOURCES as $legacyId => $config) {
            $source = Source::where('legacy_source_id', $legacyId)->first();

            if (! $source) {
                $this->warn("Missing source: {$legacyId}");

                continue;
            }

            $categoryIds = Category::whereIn('name', $config['categories'])->pluck('id');

            if ($categoryIds->count() !== count($config['categories'])) {
                $this->warn("{$legacyId}: one or more categories not found (".implode(', ', $config['categories']).'); skipping.');

                continue;
            }

            $source->update([
                'feed_url' => $config['feed_url'],
                'source_type' => 'api',
                'collector_type' => 'squarespace_events',
                'active' => true,
            ]);
            $source->defaultCategories()->sync($categoryIds);
            $activated++;
        }

        $this->info("Activated {$activated} confirmed Squarespace events sources.");

        return self::SUCCESS;
    }
}
