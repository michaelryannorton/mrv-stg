<?php

namespace Tests\Feature\Ingestion;

use App\Models\Event;
use App\Models\EventSource;
use App\Models\Source;
use App\Services\Ingestion\EventIngestor;
use App\Support\ExternalUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Addresses from feeds are cleaned before they are stored. Some feeds HTML-escape the URL field,
 * which left "&amp;" in stored query strings (77 ActiveCommunities events on staging).
 */
class UrlCleaningTest extends TestCase
{
    use RefreshDatabase;

    private const ESCAPED = 'https://anc.apm.activecommunities.com/hesperiaparks/activity/search?onlineSiteId=0&amp;activity_select_param=2&amp;viewMode=list';

    private const CLEAN = 'https://anc.apm.activecommunities.com/hesperiaparks/activity/search?onlineSiteId=0&activity_select_param=2&viewMode=list';

    public static function forms(): array
    {
        return [
            'already clean' => ['https://example.org/a?x=1&y=2', 'https://example.org/a?x=1&y=2'],
            'escaped ampersand' => [self::ESCAPED, self::CLEAN],
            'escaped twice' => ['https://example.org/a?x=1&amp;amp;y=2', 'https://example.org/a?x=1&y=2'],
            'numeric entity' => ['https://example.org/a?x=1&#38;y=2', 'https://example.org/a?x=1&y=2'],
            'hex entity' => ['https://example.org/a?x=1&#x26;y=2', 'https://example.org/a?x=1&y=2'],
            'escaped quote and angle brackets' => ['https://example.org/a?q=&quot;a&quot;&lt;b&gt;', 'https://example.org/a?q="a"<b>'],
            'percent encoding is left alone' => ['https://example.org/a%20b?x=%26', 'https://example.org/a%20b?x=%26'],
            'surrounding whitespace' => ["  https://example.org/a\n", 'https://example.org/a'],
            'control characters removed' => ["https://exam\tple.org/a\r\n", 'https://example.org/a'],
            'unicode kept' => ['https://example.org/café', 'https://example.org/café'],
            'empty' => ['', null],
            'whitespace only' => ["  \n", null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('forms')]
    public function test_clean(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, ExternalUrl::clean($input));
    }

    public function test_cleaning_is_idempotent(): void
    {
        $once = ExternalUrl::clean(self::ESCAPED);

        $this->assertSame($once, ExternalUrl::clean($once));
    }

    public function test_a_pathological_value_still_terminates(): void
    {
        $this->assertIsString(ExternalUrl::clean(str_repeat('&amp;', 500)));
    }

    private function source(): Source
    {
        return Source::create([
            'name' => 'ActiveCommunities test', 'source_type' => 'ics', 'collector_type' => 'ics',
            'feed_url' => 'https://example.test/feed.ics', 'access_scope' => 'public',
        ]);
    }

    private function item(string $externalId, ?string $url): array
    {
        return [
            'external_id' => $externalId, 'title' => 'Line dancing', 'description' => null,
            'start_at' => now()->addDay(), 'end_at' => null, 'all_day' => false,
            'location' => null, 'url' => $url, 'timezone' => 'America/Los_Angeles',
        ];
    }

    public function test_ingestion_stores_a_cleaned_address_everywhere_it_keeps_one(): void
    {
        $source = $this->source();

        app(EventIngestor::class)->ingest($source, [$this->item('a', self::ESCAPED)]);

        $event = Event::firstOrFail();
        $this->assertSame(self::CLEAN, $event->canonical_url);
        $this->assertSame(self::CLEAN, EventSource::firstOrFail()->source_url);
        $this->assertSame(self::CLEAN, $source->fresh()->sourceRecords()->firstOrFail()->raw_payload['url'] ?? null);
        $this->assertSame(self::CLEAN, $event->details_url);
    }

    public function test_a_locked_address_is_not_reported_as_drifting_when_the_feed_sends_the_escaped_form(): void
    {
        $source = $this->source();
        app(EventIngestor::class)->ingest($source, [$this->item('a', 'https://example.org/a')]);
        // The editor locked the field with a hand-typed address.
        \DB::table('events')->update(['canonical_url' => 'https://example.org/a?x=1&y=2', 'overridden_fields' => json_encode(['canonical_url'])]);

        // The feed keeps sending the same address, escaped. Same address once cleaned, so no drift.
        app(EventIngestor::class)->ingest($source, [$this->item('a', 'https://example.org/a?x=1&amp;y=2')]);

        $this->assertNull(Event::firstOrFail()->stale_fields);
    }

    public function test_a_resync_of_an_unlocked_event_repairs_its_address(): void
    {
        $source = $this->source();
        app(EventIngestor::class)->ingest($source, [$this->item('a', 'https://example.org/a')]);
        \DB::table('events')->update(['canonical_url' => 'https://example.org/a?x=1&amp;y=2']);

        app(EventIngestor::class)->ingest($source, [$this->item('a', 'https://example.org/a?x=1&amp;y=2')]);

        $this->assertSame('https://example.org/a?x=1&y=2', Event::firstOrFail()->canonical_url);
    }

    public function test_the_model_cleans_addresses_from_any_write_path(): void
    {
        $event = Event::create([
            'title' => 'Typed by hand', 'slug' => Str::random(8), 'start_at' => now()->addDay(),
            'timezone' => 'America/Los_Angeles', 'canonical_url' => '  '.self::ESCAPED,
        ]);

        $this->assertSame(self::CLEAN, $event->fresh()->canonical_url);

        $event->update(['canonical_url' => 'https://example.org/z?a=1&amp;b=2']);
        $this->assertSame('https://example.org/z?a=1&b=2', $event->fresh()->canonical_url);

        $event->update(['canonical_url' => null]);
        $this->assertNull($event->fresh()->canonical_url);
    }

    private function storeEscaped(string $title, string $url, array $extra = []): Event
    {
        $event = Event::create(['title' => $title, 'slug' => Str::random(8), 'start_at' => now()->addDay(), 'timezone' => 'America/Los_Angeles', ...$extra]);
        // Bypass the model, as rows written before the cleaning existed did.
        \DB::table('events')->where('id', $event->id)->update(['canonical_url' => $url]);

        return $event;
    }

    public function test_the_backfill_command_repairs_stored_rows_and_reports_counts(): void
    {
        $source = $this->source();
        $dirty = $this->storeEscaped('Dirty', self::ESCAPED);
        $clean = $this->storeEscaped('Clean', 'https://example.org/ok?a=1&b=2');
        $link = EventSource::create(['event_id' => $dirty->id, 'source_id' => $source->id, 'external_id' => 'x', 'source_url' => self::ESCAPED, 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $stamp = \DB::table('events')->where('id', $dirty->id)->value('updated_at');

        $this->artisan('clean:event-urls')
            ->expectsOutputToContain('Cleaned 1 event addresses and 1 event-source addresses; skipped 0')
            ->assertSuccessful();

        $this->assertSame(self::CLEAN, $dirty->fresh()->canonical_url);
        $this->assertSame(self::CLEAN, $link->fresh()->source_url);
        $this->assertSame('https://example.org/ok?a=1&b=2', $clean->fresh()->canonical_url);
        $this->assertSame($stamp, \DB::table('events')->where('id', $dirty->id)->value('updated_at'), 'updated_at is not touched');

        $this->artisan('clean:event-urls')->expectsOutputToContain('Cleaned 0 event addresses and 0 event-source addresses')->assertSuccessful();
    }

    public function test_the_backfill_dry_run_writes_nothing(): void
    {
        $dirty = $this->storeEscaped('Dirty', self::ESCAPED);

        $this->artisan('clean:event-urls --dry-run')->expectsOutputToContain('Would clean 1 event addresses')->assertSuccessful();

        $this->assertSame(self::ESCAPED, \DB::table('events')->where('id', $dirty->id)->value('canonical_url'));
    }

    public function test_the_backfill_leaves_a_hand_edited_address_alone(): void
    {
        $locked = $this->storeEscaped('Locked', self::ESCAPED, ['overridden_fields' => ['canonical_url']]);

        $this->artisan('clean:event-urls')->expectsOutputToContain('Cleaned 0 event addresses and 0 event-source addresses; skipped 1')->assertSuccessful();

        $this->assertSame(self::ESCAPED, \DB::table('events')->where('id', $locked->id)->value('canonical_url'));
    }

    public function test_the_backfill_reaches_soft_deleted_events(): void
    {
        $gone = $this->storeEscaped('Gone', self::ESCAPED);
        $gone->delete();

        $this->artisan('clean:event-urls')->assertSuccessful();

        $this->assertSame(self::CLEAN, \DB::table('events')->where('id', $gone->id)->value('canonical_url'));
    }
}
