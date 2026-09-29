<?php

namespace App\Console\Commands;

use App\Models\Source;
use Illuminate\Console\Command;

/**
 * Captures the findings of the 2026-09-28 Tribe Events endpoint-discovery pass (see
 * logs/2026 0928 2008 Tribe Events Endpoint Discovery Pass.md) as a real, idempotent, re-runnable
 * command — the discovery itself was done via one-off tinker commands against local dev, which
 * isn't something a staging/production deploy can replay. Safe to run repeatedly; every write is
 * an ->update(), not a create.
 */
class ActivateConfirmedTribeSources extends Command
{
    protected $signature = 'activate:confirmed-tribe-sources';

    protected $description = 'Apply the confirmed Tribe Events REST endpoints (feed_url, source_type, collector_type, active) and record findings on the unresolved sources';

    /** Legacy source ID => confirmed working Tribe Events REST endpoint. */
    private const CONFIRMED_ENDPOINTS = [
        'SRC-0002' => 'https://www.hesperiaparks.com/wp-json/tribe/events/v1/events',
        'SRC-0008' => 'https://parks.sbcounty.gov/wp-json/tribe/events/v1/events',
        'SRC-0009' => 'https://museum.sbcounty.gov/wp-json/tribe/events/v1/events',
        'SRC-0010' => 'https://library.sbcounty.gov/wp-json/tribe/events/v1/events',
        'SRC-0067' => 'https://bosd1.sbcounty.gov/wp-json/tribe/events/v1/events',
        'SRC-0073' => 'https://workforce.sbcounty.gov/wp-json/tribe/events/v1/events',
        'SRC-0074' => 'https://pd.sbcounty.gov/wp-json/tribe/events/v1/events',
        'SRC-0078' => 'https://ehs.sbcounty.gov/wp-json/tribe/events/v1/events',
        'SRC-0079' => 'https://our-hd.com/wp-json/tribe/events/v1/events',
        'SRC-0128' => 'https://wp.sbcounty.gov/dbh/wp-json/tribe/events/v1/events',
        'SRC-0129' => 'https://dph.sbcounty.gov/wp-json/tribe/events/v1/events',
        'SRC-0130' => 'https://airports.sbcounty.gov/wp-json/tribe/events/v1/events',
        'SRC-0132' => 'https://bosd1.sbcounty.gov/wp-json/tribe/events/v1/events',
        'SRC-0133' => 'https://museum.sbcounty.gov/wp-json/tribe/events/v1/events',
        'SRC-0135' => 'https://animalcare.sbcounty.gov/wp-json/tribe/events/v1/events',
        'SRC-0136' => 'https://hr.sbcounty.gov/wp-json/tribe/events/v1/events',
        'SRC-0137' => 'https://sheriffsjobs.sbcounty.gov/wp-json/tribe/events/v1/events',
        'SRC-0138' => 'https://childsupport.sbcounty.gov/wp-json/tribe/events/v1/events',
        'SRC-0142' => 'https://sbcfire.org/wp-json/tribe/events/v1/events',
        'SRC-0143' => 'https://wp.sbcounty.gov/tad/wp-json/tribe/events/v1/events',
        'SRC-0146' => 'https://daas.sbcounty.gov/wp-json/tribe/events/v1/events',
        'SRC-0147' => 'https://bosd3.sbcounty.gov/wp-json/tribe/events/v1/events',
        'SRC-0149' => 'https://cn.sbcounty.gov/wp-json/tribe/events/v1/events',
        'SRC-0150' => 'https://sbchp.sbcounty.gov/wp-json/tribe/events/v1/events',
        'SRC-0190' => 'https://specialdistricts.sbcounty.gov/wp-json/tribe/events/v1/events',
    ];

    /** Legacy source ID => discovery-pass finding, appended (not overwritten) onto notes. */
    private const UNRESOLVED_FINDINGS = [
        'SRC-0018' => 'Endpoint discovery 2026-09-28: /wp-json/tribe/events/v1/events returns 403 even with a browser user-agent — WAF/bot protection blocks unauthenticated REST access. Needs a different approach (real browser session or manual clip) than a direct API collector.',
        'SRC-0024' => 'Endpoint discovery 2026-09-28: /wp-json/tribe/events/v1/events returns 403 even with a browser user-agent — WAF/bot protection blocks unauthenticated REST access.',
        'SRC-0110' => 'Endpoint discovery 2026-09-28: /wp-json/tribe/events/v1/events returns 403 even with a browser user-agent — WAF/bot protection blocks unauthenticated REST access.',
        'SRC-0192' => "Endpoint discovery 2026-09-28: /wp-json/tribe/events/v1/events returns 403 even with a browser user-agent. Consistent with this site's known private/HOA-governed status (Silver Lakes Association) — may be an intentional restriction, not just bot protection.",
        'SRC-0021' => 'Endpoint discovery 2026-09-28: confirmed WordPress (wp-json root responds), but no events-related REST route exists (no tribe/events, no mec, nothing matching "event"). Not the WordPress Events Calendar plugin despite the research corpus\'s Technology/Clues text — needs manual page inspection for an ICS export link or similar.',
        'SRC-0071' => 'Endpoint discovery 2026-09-28: confirmed WordPress (wp-json root responds), but no events-related REST route exists. Needs manual page inspection.',
        'SRC-0112' => "Endpoint discovery 2026-09-28: confirmed WordPress + Modern Events Calendar (MEC) plugin, not Tribe/The Events Calendar. /wp/v2/mec-events returns real event posts (title/content/link) but lacks a clean structured date-range API like Tribe's; /mec/v1/events returned empty. A collector for this site needs the generic WP REST post type plus custom-field/meta extraction for dates — a second, separate collector target from the Tribe Events one.",
    ];

    public function handle(): int
    {
        $activated = 0;

        foreach (self::CONFIRMED_ENDPOINTS as $legacyId => $endpoint) {
            $source = Source::where('legacy_source_id', $legacyId)->first();

            if (! $source) {
                $this->warn("Missing source: {$legacyId}");

                continue;
            }

            $source->update([
                'feed_url' => $endpoint,
                'source_type' => 'api',
                'collector_type' => 'tribe_events',
                'active' => true,
            ]);
            $activated++;
        }

        $this->info("Activated {$activated} confirmed Tribe Events sources.");

        $annotated = 0;

        foreach (self::UNRESOLVED_FINDINGS as $legacyId => $finding) {
            $source = Source::where('legacy_source_id', $legacyId)->first();

            if (! $source) {
                $this->warn("Missing source: {$legacyId}");

                continue;
            }

            // Append rather than overwrite — preserves the coverage/ingestion-method notes
            // already set by the original corpus import, and is safe to re-run (won't duplicate
            // the finding if it's already present).
            if (! str_contains($source->notes ?? '', 'Endpoint discovery 2026-09-28')) {
                $source->update(['notes' => trim(($source->notes ?? '').' '.$finding)]);
            }
            $annotated++;
        }

        $this->info("Annotated {$annotated} unresolved sources with discovery findings.");

        return self::SUCCESS;
    }
}
