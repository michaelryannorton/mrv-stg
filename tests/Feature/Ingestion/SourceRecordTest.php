<?php

namespace Tests\Feature\Ingestion;

use App\Models\Event;
use App\Models\EventSource;
use App\Models\Source;
use App\Models\SourceRecord;
use App\Services\Ingestion\EventIngestor;
use App\Services\Ingestion\PayloadComparison;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A source record is written when a feed item is new or changes, not on every run, and
 * ingest:prune-source-records removes the repeats written before that rule.
 */
class SourceRecordTest extends TestCase
{
    use RefreshDatabase;

    private function source(): Source
    {
        return Source::create([
            'name' => 'Feed test', 'source_type' => 'ics', 'collector_type' => 'ics',
            'feed_url' => 'https://example.test/feed.ics', 'access_scope' => 'public',
        ]);
    }

    private function item(string $title = 'Line dancing', string $externalId = 'a'): array
    {
        return [
            'external_id' => $externalId, 'title' => $title, 'description' => null,
            'start_at' => now()->startOfDay()->addDay(), 'end_at' => null, 'all_day' => false,
            'location' => null, 'url' => 'https://example.org/a', 'timezone' => 'America/Los_Angeles',
        ];
    }

    private function ingest(Source $source, array ...$items): void
    {
        app(EventIngestor::class)->ingest($source, $items);
    }

    public function test_an_unchanged_item_writes_no_second_record_and_is_still_marked_seen(): void
    {
        $this->travelTo('2026-11-25 12:00:00');
        $source = $this->source();
        $item = $this->item();
        $this->ingest($source, $item);
        $first = SourceRecord::sole();

        $this->travel(30)->minutes();
        $this->ingest($source, $item);

        $this->assertSame(1, SourceRecord::count());
        $this->assertSame($first->id, EventSource::sole()->source_record_id);
        $this->assertSame('2026-11-25 12:30:00', (string) EventSource::sole()->last_seen_at);
        $this->assertSame(1, Event::count());
    }

    public function test_a_change_and_a_change_back_each_write_a_record(): void
    {
        $source = $this->source();

        $this->ingest($source, $this->item('Line dancing'));
        $this->ingest($source, $this->item('Square dancing'));
        $this->assertSame(2, SourceRecord::count());
        $this->assertSame('Square dancing', EventSource::sole()->sourceRecord->raw_payload['title']);
        $this->assertSame('Square dancing', Event::sole()->title);

        $this->ingest($source, $this->item('Line dancing'));
        $this->assertSame(3, SourceRecord::count());
        $this->assertSame('Line dancing', EventSource::sole()->sourceRecord->raw_payload['title']);
    }

    public function test_stored_key_order_does_not_count_as_a_change(): void
    {
        $source = $this->source();
        $item = $this->item();
        $this->ingest($source, $item);
        $record = SourceRecord::sole();
        DB::table('source_records')->where('id', $record->id)
            ->update(['raw_payload' => json_encode(array_reverse($record->raw_payload, true))]);

        $this->ingest($source, $item);

        $this->assertSame(1, SourceRecord::count());
    }

    public function test_a_link_with_no_record_gets_one(): void
    {
        $source = $this->source();
        $item = $this->item();
        $this->ingest($source, $item);
        SourceRecord::query()->delete();
        $this->assertNull(EventSource::sole()->source_record_id);

        $this->ingest($source, $item);

        $this->assertSame(SourceRecord::sole()->id, EventSource::sole()->source_record_id);
    }

    public function test_comparison_is_strict_about_values(): void
    {
        $this->assertTrue(PayloadComparison::same(['a' => 1, 'b' => null], ['b' => null, 'a' => 1]));
        $this->assertFalse(PayloadComparison::same(['a' => 1], ['a' => '1']));
        $this->assertFalse(PayloadComparison::same(['a' => null], ['a' => '']));
        $this->assertFalse(PayloadComparison::same(['a' => 1], ['a' => 1, 'b' => null]));
        $this->assertFalse(PayloadComparison::same(null, null));
    }

    /** Records as the old rule left them: one a run, the link pointing at the newest. */
    private function oldHistory(Source $source, string $externalId, array $titles): array
    {
        $this->ingest($source, $this->item($titles[0], $externalId));
        $link = EventSource::where('external_id', $externalId)->sole();
        $template = $link->sourceRecord;
        $ids = [$template->id];

        foreach (array_slice($titles, 1) as $title) {
            $record = $template->replicate(['uuid']);
            $record->raw_payload = [...$template->raw_payload, 'title' => $title];
            $record->save();
            $ids[] = $record->id;
        }

        $link->update(['source_record_id' => end($ids)]);

        return $ids;
    }

    public function test_pruning_is_a_dry_run_unless_asked_to_apply(): void
    {
        $this->oldHistory($this->source(), 'a', ['A', 'A', 'A']);
        $link = EventSource::sole()->source_record_id;

        $this->artisan('ingest:prune-source-records')
            ->expectsOutput('Source records: 3. Repeats: 2. Kept: 1. Event links to move: 1.')
            ->assertSuccessful();

        $this->assertSame(3, SourceRecord::count());
        $this->assertSame($link, EventSource::sole()->source_record_id);
    }

    public function test_pruning_keeps_the_first_of_each_stretch_and_moves_the_links(): void
    {
        $source = $this->source();
        $a = $this->oldHistory($source, 'a', ['A', 'A', 'B', 'B', 'A', 'A']);
        $b = $this->oldHistory($source, 'b', ['C', 'C']);

        $this->artisan('ingest:prune-source-records --apply --optimize')->assertSuccessful();

        $this->assertSame([$a[0], $a[2], $a[4], $b[0]], SourceRecord::orderBy('id')->pluck('id')->all());
        $this->assertSame($a[4], EventSource::where('external_id', 'a')->sole()->source_record_id);
        $this->assertSame($b[0], EventSource::where('external_id', 'b')->sole()->source_record_id);

        // A second run finds nothing, and the next ingest of the current payload writes nothing.
        $this->artisan('ingest:prune-source-records --apply')
            ->expectsOutput('Source records: 4. Repeats: 0. Kept: 4. Event links moved: 0.')
            ->assertSuccessful();
        $this->ingest($source, $this->item('A', 'a'), $this->item('C', 'b'));
        $this->assertSame(4, SourceRecord::count());
    }

    public function test_pruning_does_not_join_items_of_different_sources(): void
    {
        $this->oldHistory($this->source(), 'a', ['A']);
        $other = Source::create([
            'name' => 'Second feed', 'source_type' => 'ics', 'collector_type' => 'ics',
            'feed_url' => 'https://example.test/two.ics', 'access_scope' => 'public',
        ]);
        $this->ingest($other, $this->item('A', 'a'));

        $this->artisan('ingest:prune-source-records --apply')->assertSuccessful();

        $this->assertSame(2, SourceRecord::count());
        $this->assertSame(0, EventSource::whereNull('source_record_id')->count());
    }
}
