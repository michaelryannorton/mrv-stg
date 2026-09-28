<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Source;
use App\Models\Venue;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ImportVictorValleyEcosystem extends Command
{
    protected $signature = 'import:victor-valley-ecosystem
        {--path= : Directory containing normalized_entities.csv and normalized_sources.csv}
        {--dry-run : Parse and report counts without writing to the database}';

    protected $description = 'Import the intern research handoff (organizations, venues, sources) into the directory tables';

    /** Legacy entity ID => ['type' => 'organization'|'venue', 'id' => db id, 'name' => entity name] */
    private array $entityMap = [];

    public function handle(): int
    {
        $path = $this->option('path') ?: resource_path('data/victor-valley-ecosystem');
        $dryRun = (bool) $this->option('dry-run');

        $entitiesFile = $path.'/normalized_entities.csv';
        $sourcesFile = $path.'/normalized_sources.csv';

        if (! is_file($entitiesFile) || ! is_file($sourcesFile)) {
            $this->error("Expected normalized_entities.csv and normalized_sources.csv in {$path}");

            return self::FAILURE;
        }

        $entities = $this->readCsv($entitiesFile);
        $sources = $this->readCsv($sourcesFile);

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
