<?php

namespace App\Console\Commands;

use App\Models\Source;
use Illuminate\Console\Command;

/**
 * Captures the 2026-10-08 findings from the second-collector discovery pass and the
 * unresolved-sources pass (see logs/2026 1008 1645 Second Collector Discovery, Approval Warning,
 * And Unresolved Sources.md) as a real, idempotent command. The investigation was done against
 * local dev; this lets staging carry the same notes on each source record, the same way
 * activate:confirmed-tribe-sources did for the 2026-09-28 pass. Notes are appended, never
 * overwritten, and a row already carrying this pass's marker is skipped.
 */
class AnnotateDiscoveryFollowups extends Command
{
    protected $signature = 'annotate:discovery-followups';

    protected $description = 'Append the 2026-10-08 discovery-pass findings to the notes on the sources they concern';

    private const MARKER = 'Follow-up 2026-10-08:';

    private const LIBRARY_BRANCH = 'Already covered: this is a slice of source 225 (county-wide Library Tribe feed, active). A per-branch collector would duplicate its events. The ical=1 URL on this domain is also blocked by a WAF rule.';

    private const WAF_NO_WORKAROUND = 'REST 403 is a WAF block. Decision: do not work around it. Use the clipper for individual events, or ask the %s for an ICS/feed.';

    private const EDLIO = 'Edlio site. Page returns 403 to unauthenticated requests (WAF). Decision: do not work around it.';

    private const FINALSITE = '/fs/calendar-manager/events.ics?calendar_ids=<ids> is a valid endpoint but returned an empty calendar for every parameter combination tried; the required parameter is unknown.';

    /** Legacy source ID => finding to append. */
    private function findings(): array
    {
        return [
            // Second-collector discovery pass (sources typed ics with no feed_url).
            'SRC-0011' => self::LIBRARY_BRANCH,
            'SRC-0012' => self::LIBRARY_BRANCH,
            'SRC-0013' => self::LIBRARY_BRANCH,
            'SRC-0148' => 'Already covered by source 358 (TAD Tribe feed, active). This row is a page on the same calendar.',
            'SRC-0134' => 'Checked: the Tribe REST API on main.sbcounty.gov holds only 1 past event, so the countywide calendar is not served from it. Real data source not yet identified.',
            'SRC-0182' => 'Squarespace site. ?format=ical on /events returns HTML, but /events?format=json returns structured collection JSON. Collected by ingest:squarespace-events (built 2026-10-08, see logs/2026 1008 1658 Squarespace Events Collector Built.md). The only other Squarespace source in the corpus, 414 (Revival Dance Lab), has no events collection.',
            'SRC-0084' => 'Checked: no Tribe REST API (404) and ?ical=1 returns HTML. Needs page inspection or the clipper.',
            'SRC-0015' => 'Finalsite calendar. '.self::FINALSITE.' Calendar element exposes data-calendar-ids and a feed uuid.',
            'SRC-0016' => 'Finalsite calendar (calendar ids 362,351,350,353). '.self::FINALSITE,
            'SRC-0153' => 'Finalsite calendar (calendar ids 390,384,381,380). Same unresolved events.ics behavior as SRC-0016.',
            'SRC-0186' => self::EDLIO,
            'SRC-0189' => self::EDLIO,

            // The 6 sources left unresolved by the 2026-09-28 Tribe endpoint-discovery pass.
            'SRC-0018' => sprintf(self::WAF_NO_WORKAROUND, 'site owner'),
            'SRC-0024' => sprintf(self::WAF_NO_WORKAROUND, 'venue'),
            'SRC-0110' => sprintf(self::WAF_NO_WORKAROUND, 'venue'),
            'SRC-0192' => 'REST 403, likely an intentional restriction on a private HOA site. Decision: no collector; leave to the clipper or a public submission only if the association publishes events openly.',
            'SRC-0021' => 'vvcca.org/concerts page inspected: no Event JSON-LD, no ICS/Google Calendar link, no events plugin (only cleantalk, hummingbird). Small seasonal concert series, so route through the clipper (/admin/clip) or a public submission, not a collector.',
            'SRC-0071' => 'avvillage.org /events/ and a sample event page inspected: no Event JSON-LD, no ICS link; events appear to be a custom post type not exposed over REST. Few annual events, so route through the clipper, not a collector.',
        ];
    }

    public function handle(): int
    {
        $annotated = 0;
        $skipped = 0;

        foreach ($this->findings() as $legacyId => $finding) {
            $source = Source::where('legacy_source_id', $legacyId)->first();

            if (! $source) {
                $this->warn("Missing source: {$legacyId}");

                continue;
            }

            if (str_contains($source->notes ?? '', self::MARKER)) {
                $skipped++;

                continue;
            }

            $source->update(['notes' => trim(($source->notes ?? '').' '.self::MARKER.' '.$finding)]);
            $annotated++;
        }

        $this->info("Annotated {$annotated} sources; {$skipped} already carried this pass's note.");

        return self::SUCCESS;
    }
}
