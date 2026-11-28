<?php

namespace App\Console\Commands;

use App\Models\SourceRecord;
use App\Services\Ingestion\PayloadComparison;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes the repeated source records written before 2026-11-25, when every ingestion run stored every
 * item again. For each source and item, in the order written, each stretch of identical payloads keeps
 * its first row; event links pointing into the stretch are moved to that row and the others are deleted.
 * A change, and a change back, are both kept. Records without an item identifier are left alone.
 *
 * A dry run unless --apply is given. Safe to run again. Not scheduled.
 */
class PruneSourceRecords extends Command
{
    protected $signature = 'ingest:prune-source-records
        {--apply : Delete the repeats; without this nothing changes}
        {--optimize : With --apply, rebuild the table afterwards so the space is returned (MySQL)}';

    protected $description = 'Remove repeated, identical source records, keeping each change';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $total = SourceRecord::query()->count();
        $repeats = 0;
        $moved = 0;

        $items = SourceRecord::query()
            ->whereNotNull('external_id')
            ->where('record_type', 'event')
            ->select('source_id', 'external_id')
            ->groupBy('source_id', 'external_id')
            ->havingRaw('COUNT(*) > 1')
            ->toBase()
            ->get();

        foreach ($items as $item) {
            DB::transaction(function () use ($item, $apply, &$repeats, &$moved) {
                foreach ($this->repeatsOf((int) $item->source_id, (string) $item->external_id) as $keep => $ids) {
                    foreach (array_chunk($ids, 500) as $chunk) {
                        $links = DB::table('event_sources')->whereIn('source_record_id', $chunk);
                        $repeats += count($chunk);

                        if (! $apply) {
                            $moved += $links->count();

                            continue;
                        }

                        // Links move first: deleting a record a link points at would empty the link.
                        $moved += $links->update(['source_record_id' => $keep]);
                        SourceRecord::query()->whereIn('id', $chunk)->delete();
                    }
                }
            });
        }

        $this->info(sprintf(
            'Source records: %d. Repeats: %d. Kept: %d. Event links %s: %d.',
            $total,
            $repeats,
            $total - $repeats,
            $apply ? 'moved' : 'to move',
            $moved,
        ));

        if (! $apply) {
            $this->info('Dry run: nothing changed. Run again with --apply to delete the repeats.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Deleted. Rows now: %d. Event links without a record: %d.',
            SourceRecord::query()->count(),
            DB::table('event_sources')->whereNull('source_record_id')->count(),
        ));

        if ($this->option('optimize') && in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            // OPTIMIZE returns a result set, so it is read with select().
            foreach (DB::select('OPTIMIZE TABLE source_records') as $row) {
                $this->line(implode(' ', (array) $row));
            }
        }

        return self::SUCCESS;
    }

    /**
     * The rows of one item that repeat the row before them.
     *
     * @return array<int, list<int>> kept record id => ids that repeat it
     */
    private function repeatsOf(int $sourceId, string $externalId): array
    {
        $rows = SourceRecord::query()
            ->where('source_id', $sourceId)
            ->where('external_id', $externalId)
            ->where('record_type', 'event')
            ->select('id', 'raw_payload', 'content_hash')
            ->lazyById(500);

        $kept = null;
        $repeats = [];

        foreach ($rows as $row) {
            if ($kept !== null && $kept->content_hash === $row->content_hash && PayloadComparison::same($kept->raw_payload, $row->raw_payload)) {
                $repeats[$kept->id][] = $row->id;

                continue;
            }

            $kept = $row;
        }

        return $repeats;
    }
}
