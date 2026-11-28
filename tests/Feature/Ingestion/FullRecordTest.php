<?php

namespace Tests\Feature\Ingestion;

use App\Models\Source;
use App\Models\SourceRecord;
use App\Services\Ingestion\EventIngestor;
use App\Services\Ingestion\FeedArchive;
use App\Services\Ingestion\FeedRunner;
use App\Services\Ingestion\IcsCollector;
use App\Services\Ingestion\TribeEventsCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Everything a feed sends is kept (Michael, 2026-11-27): each item's own record beside the
 * normalized fields, and the latest whole response of each feed.
 */
class FullRecordTest extends TestCase
{
    use RefreshDatabase;

    private function source(string $collector = 'ics'): Source
    {
        return Source::create([
            'name' => 'Feed test', 'source_type' => 'ics', 'collector_type' => $collector,
            'feed_url' => 'https://example.test/feed', 'access_scope' => 'public', 'active' => true,
        ]);
    }

    private function item(array $overrides = []): array
    {
        return [
            'external_id' => 'a', 'title' => 'Line dancing', 'description' => null,
            'start_at' => now()->startOfDay()->addDay(), 'end_at' => null, 'all_day' => false,
            'location' => null, 'url' => 'https://example.org/a', 'timezone' => 'America/Los_Angeles',
            'raw' => '{"title":"Line dancing","cost":"Free"}', ...$overrides,
        ];
    }

    private function ingest(Source $source, array $item): void
    {
        app(EventIngestor::class)->ingest($source, [$item]);
    }

    private function ics(string $stamp, string $extra = ''): string
    {
        $day = now()->addDays(3)->format('Ymd');

        return implode("\r\n", array_filter([
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//test//test//EN', 'X-WR-CALNAME:Test calendar',
            'BEGIN:VEVENT', 'UID:weekly@example.test', "DTSTAMP:{$stamp}", "DTSTART:{$day}T180000Z",
            'RRULE:FREQ=WEEKLY;COUNT=3', 'SUMMARY:Weekly meetup', 'CATEGORIES:Social', $extra,
            'END:VEVENT', 'END:VCALENDAR',
        ]))."\r\n";
    }

    public function test_the_full_record_is_stored_beside_the_normalized_fields(): void
    {
        $this->ingest($this->source(), $this->item());

        $record = SourceRecord::sole();
        $this->assertSame('{"title":"Line dancing","cost":"Free"}', $record->raw_text);
        $this->assertSame(64, strlen($record->content_hash));
        $this->assertArrayNotHasKey('raw', $record->raw_payload);
        $this->assertArrayNotHasKey('raw_text', $record->toArray());
    }

    public function test_a_change_the_normalized_fields_do_not_show_writes_a_new_record(): void
    {
        $source = $this->source();
        $this->ingest($source, $this->item());
        $this->ingest($source, $this->item());
        $this->assertSame(1, SourceRecord::count());

        $this->ingest($source, $this->item(['raw' => '{"title":"Line dancing","cost":"$5"}']));

        $this->assertSame(2, SourceRecord::count());
    }

    public function test_a_stamp_that_changes_on_every_download_is_not_a_change(): void
    {
        $source = $this->source();
        $this->ingest($source, $this->item(['raw' => 'stamp 1', 'raw_stable' => 'same']));
        $this->ingest($source, $this->item(['raw' => 'stamp 2', 'raw_stable' => 'same']));

        $this->assertSame(1, SourceRecord::count());
        $this->assertSame('stamp 1', SourceRecord::sole()->raw_text);
    }

    public function test_a_record_from_before_full_records_is_filled_in_place(): void
    {
        $source = $this->source();
        $item = $this->item();
        unset($item['raw']);
        $this->ingest($source, $item);
        $this->assertNull(SourceRecord::sole()->raw_text);

        $this->ingest($source, $this->item());

        $this->assertSame('{"title":"Line dancing","cost":"Free"}', SourceRecord::sole()->raw_text);
    }

    public function test_a_calendar_item_keeps_its_original_entry_and_the_download_stamp_does_not_count(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->ics('20260101T000000Z'))
            ->push($this->ics('20260102T000000Z'))]);

        $first = (new IcsCollector)->fetch('https://example.test/feed');
        $second = (new IcsCollector)->fetch('https://example.test/feed');

        $this->assertCount(3, $first);
        $this->assertStringContainsString('RRULE:FREQ=WEEKLY;COUNT=3', $first[0]['raw']);
        $this->assertStringContainsString('CATEGORIES:Social', $first[0]['raw']);
        $this->assertNotSame($first[0]['raw'], $second[0]['raw']);
        $this->assertSame($first[0]['raw_stable'], $second[0]['raw_stable']);
    }

    public function test_a_wordpress_item_keeps_fields_the_app_does_not_use(): void
    {
        Http::fake(['*' => Http::response(['total_pages' => 1, 'events' => [[
            'id' => 5, 'title' => 'Story time', 'start_date' => now()->addDay()->format('Y-m-d H:i:s'), 'website' => '',
            'cost' => 'Free', 'venue' => ['venue' => 'Library', 'city' => 'Hesperia', 'zip' => '92345'],
        ]]])]);

        $items = (new TribeEventsCollector)->fetch('https://example.test/feed');
        $raw = json_decode($items[0]['raw'], true);

        $this->assertSame('92345', $raw['venue']['zip']);
        $this->assertSame('Free', $raw['cost']);
    }

    public function test_a_wordpress_feed_that_repeats_its_pages_is_not_read_to_the_end(): void
    {
        Http::fake(['*' => Http::response(['total_pages' => 147, 'events' => [[
            'id' => 5, 'title' => 'Same', 'start_date' => now()->addDay()->format('Y-m-d H:i:s'), 'website' => '',
        ]]])]);

        $items = (new TribeEventsCollector)->fetch('https://example.test/feed');

        $this->assertCount(1, $items);
        Http::assertSentCount(2);
    }

    public function test_the_latest_response_is_kept_compressed_and_replaced(): void
    {
        Storage::fake('local');
        $source = $this->source();
        $path = FeedArchive::directory($source).'/calendar.gz';
        Storage::disk('local')->put(FeedArchive::directory($source).'/page-9.gz', 'old');
        Http::fake(['*' => Http::sequence()
            ->push($this->ics('20260101T000000Z'))
            ->push($this->ics('20260102T000000Z'))
            ->push('gone', 500)]);

        app(FeedRunner::class)->run($source);
        $this->assertStringContainsString('20260101T000000Z', gzdecode(Storage::disk('local')->get($path)));
        $this->assertSame([$path], Storage::disk('local')->files(FeedArchive::directory($source)));

        app(FeedRunner::class)->run($source);
        $this->assertStringContainsString('X-WR-CALNAME:Test calendar', gzdecode(Storage::disk('local')->get($path)));
        $this->assertStringContainsString('20260102T000000Z', gzdecode(Storage::disk('local')->get($path)));

        // A failed run leaves the last good copy and records the error.
        $result = app(FeedRunner::class)->run($source);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('500', $source->fresh()->last_error);
        $this->assertStringContainsString('20260102T000000Z', gzdecode(Storage::disk('local')->get($path)));
    }

    public function test_a_source_with_an_unknown_collector_records_an_error(): void
    {
        $source = $this->source('manual');

        $this->assertFalse(app(FeedRunner::class)->run($source)['ok']);
        $this->assertStringContainsString('No collector', $source->fresh()->last_error);
    }
}
