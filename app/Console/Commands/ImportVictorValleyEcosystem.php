<?php

namespace App\Console\Commands;

use App\Models\EventSeries;
use App\Models\EventSeriesProducer;
use App\Models\Organization;
use App\Models\Relationship;
use App\Models\Source;
use App\Models\Venue;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ImportVictorValleyEcosystem extends Command
{
    protected $signature = 'import:victor-valley-ecosystem
        {--path= : Directory containing the normalized_*.csv / production_*.csv exports}
        {--dry-run : Parse and report counts without writing to the database}';

    protected $description = 'Import the intern research handoff (organizations, venues, sources, series, producers, relationship graph) into the directory tables';

    /** Legacy entity ID => ['type' => 'organization'|'venue', 'id' => db id, 'name' => entity name] */
    private array $entityMap = [];

    /** Canonical series legacy ID => db id */
    private array $seriesMap = [];

    public function handle(): int
    {
        $path = $this->option('path') ?: resource_path('data/victor-valley-ecosystem');
        $dryRun = (bool) $this->option('dry-run');

        $entitiesFile = $path.'/normalized_entities.csv';
        $sourcesFile = $path.'/normalized_sources.csv';
        $seriesFile = $path.'/production_series.csv';
        $producersFile = $path.'/series_producers.csv';
        $relationshipsFile = $path.'/production_relationships.csv';

        foreach ([$entitiesFile, $sourcesFile, $seriesFile, $producersFile, $relationshipsFile] as $required) {
            if (! is_file($required)) {
                $this->error("Expected file missing: {$required}");

                return self::FAILURE;
            }
        }

        $entities = $this->readCsv($entitiesFile);
        $sources = $this->readCsv($sourcesFile);
        $seriesRows = $this->readCsv($seriesFile);
        $producerRows = $this->readCsv($producersFile);
        $relationshipRows = $this->readCsv($relationshipsFile);

        // Legacy ID (canonical OR merge_alias) => canonical row, so an alias-published
        // source still resolves to the one real entity record.
        $canonicalByLegacyId = [];
        foreach ($entities as $row) {
            $canonicalByLegacyId[$row['Legacy Entity ID']] = $row['Canonical Entity ID'];
        }
        $rowsByLegacyId = [];
        foreach ($entities as $row) {
            $rowsByLegacyId[$row['Legacy Entity ID']] = $row;
        }

        [$orgCreated, $orgUpdated, $venueCreated, $venueUpdated] = [0, 0, 0, 0];

        // Pass 1: create/update every canonical entity as an organization or a venue.
        foreach ($entities as $row) {
            if ($row['Disposition'] !== 'canonical') {
                continue;
            }

            $isVenue = $row['Normalized Entity Type'] === 'venue';
            $verified = str_contains(strtolower($row['Research Status'] ?? ''), 'verified');

            $attributes = [
                'name' => $row['Entity Name'],
                'city' => $row['City / Area Raw'] ?: null,
                'website_url' => $this->cleanUrl($row['Primary URL'] ?? null),
                'verification_status' => $verified ? 'verified' : 'unverified',
            ];

            if ($dryRun) {
                $this->entityMap[$row['Legacy Entity ID']] = [
                    'type' => $isVenue ? 'venue' : 'organization',
                    'id' => null,
                    'name' => $row['Entity Name'],
                ];

                $isVenue ? $venueCreated++ : $orgCreated++;

                continue;
            }

            if ($isVenue) {
                $venue = Venue::query()->where('legacy_entity_id', $row['Legacy Entity ID'])->first();
                $wasNew = ! $venue;

                $venue = Venue::updateOrCreate(
                    ['legacy_entity_id' => $row['Legacy Entity ID']],
                    [...$attributes, 'venue_type' => $row['Raw Entity Type'] ?: null, 'slug' => $venue?->slug ?? $this->uniqueSlug(Venue::class, $row['Entity Name'])]
                );

                $wasNew ? $venueCreated++ : $venueUpdated++;

                $this->entityMap[$row['Legacy Entity ID']] = [
                    'type' => 'venue', 'id' => $venue->id, 'name' => $row['Entity Name'],
                ];
            } else {
                $org = Organization::query()->where('legacy_entity_id', $row['Legacy Entity ID'])->first();
                $wasNew = ! $org;

                $org = Organization::updateOrCreate(
                    ['legacy_entity_id' => $row['Legacy Entity ID']],
                    [...$attributes, 'organization_type' => $row['Normalized Entity Type'] ?: null, 'slug' => $org?->slug ?? $this->uniqueSlug(Organization::class, $row['Entity Name'])]
                );

                $wasNew ? $orgCreated++ : $orgUpdated++;

                $this->entityMap[$row['Legacy Entity ID']] = [
                    'type' => 'organization', 'id' => $org->id, 'name' => $row['Entity Name'],
                ];
            }
        }

        $this->info("Organizations: {$orgCreated} created, {$orgUpdated} updated.");
        $this->info("Venues: {$venueCreated} created, {$venueUpdated} updated.");

        // Pass 2: attach venues to their parent organization where the raw parent-org
        // label matches an imported organization's name exactly.
        $orgIdsByName = [];
        foreach ($this->entityMap as $entry) {
            if ($entry['type'] === 'organization') {
                $orgIdsByName[$entry['name']] = $entry['id'];
            }
        }

        $venueParentLinks = 0;
        if (! $dryRun) {
            foreach ($entities as $row) {
                if ($row['Disposition'] !== 'canonical' || $row['Normalized Entity Type'] !== 'venue') {
                    continue;
                }

                $parentRaw = trim($row['Parent Organization Raw'] ?? '');
                if ($parentRaw === '' || $parentRaw === '—' || ! isset($orgIdsByName[$parentRaw])) {
                    continue;
                }

                $entry = $this->entityMap[$row['Legacy Entity ID']];
                Venue::where('id', $entry['id'])->update(['organization_id' => $orgIdsByName[$parentRaw]]);
                $venueParentLinks++;
            }
        }
        $this->info("Venues linked to a parent organization: {$venueParentLinks}.");

        // Pass 3: sources, publisher resolved through the entity map.
        [$sourceCreated, $sourceUpdated, $unresolvedPublishers] = [0, 0, 0];

        foreach ($sources as $row) {
            $legacySourceId = $row['Legacy Source ID'];
            $publisherLegacyId = $row['Resolved Publisher Entity ID'] ?: null;
            $publisherEntry = null;

            if ($publisherLegacyId) {
                $canonicalId = $canonicalByLegacyId[$publisherLegacyId] ?? null;
                $publisherEntry = $canonicalId ? ($this->entityMap[$canonicalId] ?? null) : null;

                if (! $publisherEntry) {
                    $unresolvedPublishers++;
                }
            }

            $sourceType = $this->guessSourceType($row['Feed/API/ICS'] ?? '', $row['Expected Ingestion Method'] ?? '');

            $noteParts = array_filter([
                $row['Coverage / What It Produces'] ? 'Coverage: '.$row['Coverage / What It Produces'] : null,
                $row['Expected Ingestion Method'] ? 'Expected ingestion: '.$row['Expected Ingestion Method'] : null,
                $row['Integrity / Migration Notes'] ? 'Migration notes: '.$row['Integrity / Migration Notes'] : null,
            ]);

            $attributes = [
                'name' => $row['Source Name'],
                'organization_id' => $publisherEntry && $publisherEntry['type'] === 'organization' ? $publisherEntry['id'] : null,
                'venue_id' => $publisherEntry && $publisherEntry['type'] === 'venue' ? $publisherEntry['id'] : null,
                'source_type' => $sourceType,
                'source_class' => $row['Normalized Source Class'] ?: null,
                'technology_clues' => $row['Technology / Clues'] ?: null,
                'base_url' => $this->cleanUrl($row['Canonical URL'] ?? null),
                'geographic_scope' => $row['Geography Raw'] ?: null,
                'collector_type' => 'manual',
                'active' => false,
                'access_scope' => $row['Normalized Access Scope'] ?: 'unknown',
                'discovery_value' => $this->intOrNull($row['Discovery Value'] ?? null),
                'canonical_reliability' => $this->intOrNull($row['Canonical Reliability'] ?? null),
                'ingestion_friendliness' => $this->intOrNull($row['Ingestion Friendliness'] ?? null),
                'notes' => $noteParts ? implode(' ', $noteParts) : null,
            ];

            if ($dryRun) {
                $sourceCreated++;

                continue;
            }

            $existing = Source::query()->where('legacy_source_id', $legacySourceId)->first();
            $wasNew = ! $existing;

            Source::updateOrCreate(['legacy_source_id' => $legacySourceId], $attributes);

            $wasNew ? $sourceCreated++ : $sourceUpdated++;
        }

        $this->info("Sources: {$sourceCreated} created, {$sourceUpdated} updated.");
        if ($unresolvedPublishers > 0) {
            $this->warn("Sources with an unresolved publisher entity: {$unresolvedPublishers}.");
        }

        $canonicalSeriesByLegacyId = [];
        foreach ($seriesRows as $row) {
            $canonicalSeriesByLegacyId[$row['Legacy Series ID']] = $row['Canonical Series ID'];
        }

        [$seriesCreated, $seriesUpdated] = [0, 0];

        foreach ($seriesRows as $row) {
            if ($row['Disposition'] !== 'canonical') {
                continue;
            }

            $producerLegacyId = $row['Primary Producer Entity ID'] ?: null;
            $producerEntry = null;
            if ($producerLegacyId) {
                $canonicalId = $canonicalByLegacyId[$producerLegacyId] ?? null;
                $producerEntry = $canonicalId ? ($this->entityMap[$canonicalId] ?? null) : null;
            }

            $noteParts = array_filter([
                $row['Ingestion Notes'] ? 'Ingestion notes: '.$row['Ingestion Notes'] : null,
                $row['Migration Notes'] ? 'Migration notes: '.$row['Migration Notes'] : null,
            ]);

            $attributes = [
                'title' => $row['Series Name'],
                'organization_id' => $producerEntry && $producerEntry['type'] === 'organization' ? $producerEntry['id'] : null,
                'recurrence_rule' => $row['Recurrence Type'] ?: null,
                'cadence_raw' => $row['Cadence Raw'] ?: null,
                'event_type_raw' => $row['Event Type Raw'] ?: null,
                'notes' => $noteParts ? implode(' ', $noteParts) : null,
            ];

            if ($dryRun) {
                $this->seriesMap[$row['Canonical Series ID']] = null;
                $seriesCreated++;

                continue;
            }

            $existing = EventSeries::query()->where('legacy_series_id', $row['Legacy Series ID'])->first();
            $wasNew = ! $existing;

            $series = EventSeries::updateOrCreate(
                ['legacy_series_id' => $row['Legacy Series ID']],
                [...$attributes, 'slug' => $existing?->slug ?? $this->uniqueSlug(EventSeries::class, $row['Series Name'])]
            );

            $wasNew ? $seriesCreated++ : $seriesUpdated++;
            $this->seriesMap[$row['Canonical Series ID']] = $series->id;
        }

        $this->info("Event series: {$seriesCreated} created, {$seriesUpdated} updated.");

        [$producerLinksCreated, $producerLinksUpdated, $unresolvedProducerLinks] = [0, 0, 0];

        foreach ($producerRows as $row) {
            $canonicalSeriesId = $canonicalSeriesByLegacyId[$row['Legacy Series ID']] ?? $row['Canonical Series ID'];
            $seriesDbId = $this->seriesMap[$canonicalSeriesId] ?? null;

            $canonicalEntityId = $canonicalByLegacyId[$row['Producer Entity ID']] ?? null;
            $producerEntry = $canonicalEntityId ? ($this->entityMap[$canonicalEntityId] ?? null) : null;

            if (! $seriesDbId || ! $producerEntry) {
                $unresolvedProducerLinks++;

                continue;
            }

            if ($dryRun) {
                $producerLinksCreated++;

                continue;
            }

            $existing = EventSeriesProducer::query()->where('legacy_relation_id', $row['Series Producer Rel ID'])->first();
            $wasNew = ! $existing;

            EventSeriesProducer::updateOrCreate(
                ['legacy_relation_id' => $row['Series Producer Rel ID']],
                [
                    'event_series_id' => $seriesDbId,
                    'organization_id' => $producerEntry['type'] === 'organization' ? $producerEntry['id'] : null,
                    'venue_id' => $producerEntry['type'] === 'venue' ? $producerEntry['id'] : null,
                    'role' => $row['Role'],
                    'producer_raw' => $row['Producer Raw'] ?: null,
                ]
            );

            $wasNew ? $producerLinksCreated++ : $producerLinksUpdated++;
        }

        $this->info("Series-producer links: {$producerLinksCreated} created, {$producerLinksUpdated} updated.");
        if ($unresolvedProducerLinks > 0) {
            $this->warn("Series-producer links skipped (series or producer didn't resolve): {$unresolvedProducerLinks}.");
        }

        $sourceIdByLegacyId = Source::query()->whereNotNull('legacy_source_id')->pluck('id', 'legacy_source_id')->all();
        [$relCreated, $relUpdated] = [0, 0];

        foreach ($relationshipRows as $row) {
            if ($dryRun) {
                $relCreated++;

                continue;
            }

            $existing = Relationship::query()->where('production_rel_id', $row['Production Rel ID'])->first();
            $wasNew = ! $existing;

            Relationship::updateOrCreate(
                ['production_rel_id' => $row['Production Rel ID']],
                [
                    'legacy_edge_id' => $row['Legacy Edge ID'] ?: null,
                    'from_type' => $row['From Type'],
                    'from_legacy_id' => $row['From Legacy ID'] ?: null,
                    'from_canonical_id' => $row['From Canonical ID'] ?: null,
                    'from_name' => $row['From Name'] ?: null,
                    'relationship_type' => $row['Relationship'],
                    'to_type' => $row['To Type'],
                    'to_legacy_id' => $row['To Legacy ID'] ?: null,
                    'to_canonical_id' => $row['To Canonical ID'] ?: null,
                    'to_name' => $row['To Name'] ?: null,
                    'evidence_source_id' => $sourceIdByLegacyId[$row['Evidence Source ID']] ?? null,
                    'confidence' => $row['Confidence'] ?: null,
                    'resolution_method' => $row['Resolution Method'] ?: null,
                    'notes' => $row['Notes'] ?: null,
                ]
            );

            $wasNew ? $relCreated++ : $relUpdated++;
        }

        $this->info("Relationship edges: {$relCreated} created, {$relUpdated} updated.");

        $venueEnriched = 0;

        if (! $dryRun) {
            $occursAtEdges = Relationship::query()
                ->where('relationship_type', 'occurs_at')
                ->where('from_type', 'Series')
                ->where('to_type', 'Entity')
                ->get();

            foreach ($occursAtEdges as $edge) {
                $seriesDbId = $this->seriesMap[$edge->from_canonical_id] ?? null;
                $entry = $edge->to_canonical_id ? ($this->entityMap[$edge->to_canonical_id] ?? null) : null;

                if (! $seriesDbId || ! $entry || $entry['type'] !== 'venue') {
                    continue;
                }

                $series = EventSeries::find($seriesDbId);
                if ($series && ! $series->venue_id) {
                    $series->venue_id = $entry['id'];
                    $series->save();
                    $venueEnriched++;
                }
            }
        }

        $this->info("Series venues backfilled from the relationship graph: {$venueEnriched}.");

        if ($dryRun) {
            $this->comment('Dry run — no database writes were made.');
        }

        return self::SUCCESS;
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null] || $line === false) {
                continue;
            }
            $rows[] = array_combine($header, $line);
        }

        fclose($handle);

        return $rows;
    }

    private function uniqueSlug(string $model, string $name): string
    {
        $base = Str::slug($name) ?: 'entity';
        $slug = $base;
        $i = 1;

        while ($model::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }

    private function cleanUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        return ($url === '' || $url === '—') ? null : $url;
    }

    private function intOrNull(?string $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }

    private function guessSourceType(string $feedClues, string $ingestionMethod): string
    {
        $text = strtolower($feedClues.' '.$ingestionMethod);

        return match (true) {
            str_contains($text, 'ical') || str_contains($text, 'ics') => 'ics',
            str_contains($text, 'google calendar') => 'google_calendar',
            str_contains($text, 'api') => 'api',
            str_contains($text, 'rss') => 'rss',
            str_contains($text, 'structured html') => 'structured_html',
            default => 'html_scraper',
        };
    }
}
