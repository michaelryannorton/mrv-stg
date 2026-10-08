<?php

namespace Tests\Feature\Ingestion;

use App\Services\Ingestion\SquarespaceEventsCollector;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SquarespaceEventsCollectorTest extends TestCase
{
    private function event(string $id, int $daysAhead, array $overrides = []): array
    {
        $start = now()->addDays($daysAhead)->getTimestamp() * 1000;

        return array_merge([
            'id' => $id,
            'title' => 'Event '.$id,
            'startDate' => $start,
            'endDate' => $start + 3_600_000,
            'fullUrl' => '/events/event-'.$id,
            'body' => '<p>Hello</p><style>.x{color:red}</style><p>World</p>',
            'location' => ['addressTitle' => 'Arts Center', 'addressLine1' => '1 Main St', 'addressLine2' => 'Town, CA, 90000', 'addressCountry' => 'United States'],
        ], $overrides);
    }

    private function page(array $upcoming, array $pagination = [], array $website = []): array
    {
        return [
            'website' => array_merge(['timeZone' => 'America/Los_Angeles', 'baseUrl' => 'https://example.test'], $website),
            'upcoming' => $upcoming,
            'pagination' => $pagination,
        ];
    }

    public function test_normalizes_an_event(): void
    {
        Http::fake(['*' => Http::response($this->page([$this->event('a1', 3)]))]);

        $items = (new SquarespaceEventsCollector)->fetch('https://example.test/events');

        $this->assertCount(1, $items);
        $this->assertSame('a1', $items[0]['external_id']);
        $this->assertSame('Event a1', $items[0]['title']);
        $this->assertSame('America/Los_Angeles', $items[0]['timezone']);
        $this->assertSame('https://example.test/events/event-a1', $items[0]['url']);
        $this->assertSame('Arts Center, 1 Main St, Town, CA, 90000', $items[0]['location']);
        $this->assertFalse($items[0]['all_day']);
        $this->assertEquals(3600, $items[0]['start_at']->diffInSeconds($items[0]['end_at']));
    }

    public function test_description_drops_style_blocks_and_keeps_paragraph_breaks(): void
    {
        Http::fake(['*' => Http::response($this->page([$this->event('a1', 3)]))]);

        $items = (new SquarespaceEventsCollector)->fetch('https://example.test/events');

        $this->assertSame("Hello\n\nWorld", $items[0]['description']);
    }

    public function test_requests_json_format_and_keeps_an_existing_query_string(): void
    {
        Http::fake(['*' => Http::response($this->page([]))]);

        (new SquarespaceEventsCollector)->fetch('https://example.test/events?category=music');

        Http::assertSent(fn ($request) => $request->url() === 'https://example.test/events?category=music&format=json');
    }

    public function test_follows_pagination_only_while_a_page_is_full_and_applies_the_horizon(): void
    {
        Http::fake([
            '*offset=99*' => Http::response($this->page(
                [$this->event('c3', 3), $this->event('d4', 400)],
                ['nextPage' => true, 'nextPageUrl' => '/events?offset=100', 'pageSize' => 3],
            )),
            '*' => Http::response($this->page(
                [$this->event('a1', 1), $this->event('b2', 2)],
                ['nextPage' => true, 'nextPageUrl' => '/events?offset=99', 'pageSize' => 2],
            )),
        ]);

        $items = (new SquarespaceEventsCollector)->fetch('https://example.test/events');

        $this->assertSame(['a1', 'b2', 'c3'], array_column($items, 'external_id'));
        Http::assertSentCount(2);
    }

    public function test_a_short_first_page_does_not_follow_next_page(): void
    {
        Http::fake(['*' => Http::response($this->page(
            [$this->event('a1', 1)],
            ['nextPage' => true, 'nextPageUrl' => '/events?offset=5', 'pageSize' => 30],
        ))]);

        (new SquarespaceEventsCollector)->fetch('https://example.test/events');

        Http::assertSentCount(1);
    }

    public function test_a_site_that_ignores_the_offset_does_not_loop(): void
    {
        Http::fake(['*' => Http::response($this->page(
            [$this->event('a1', 1), $this->event('b2', 2)],
            ['nextPage' => true, 'nextPageUrl' => '/events?offset=5', 'pageSize' => 2],
        ))]);

        $items = (new SquarespaceEventsCollector)->fetch('https://example.test/events');

        $this->assertCount(2, $items);
        Http::assertSentCount(2);
    }

    public function test_missing_or_non_iana_timezone_falls_back_to_pacific(): void
    {
        Http::fake(['*' => Http::response($this->page([$this->event('a1', 1)], [], ['timeZone' => 'UTC-7']))]);

        $items = (new SquarespaceEventsCollector)->fetch('https://example.test/events');

        $this->assertSame('America/Los_Angeles', $items[0]['timezone']);
    }

    public function test_skips_items_without_an_id_or_start_date(): void
    {
        Http::fake(['*' => Http::response($this->page([
            $this->event('a1', 1),
            $this->event('b2', 1, ['startDate' => null, 'structuredContent' => null]),
            $this->event('', 1),
        ]))]);

        $items = (new SquarespaceEventsCollector)->fetch('https://example.test/events');

        $this->assertSame(['a1'], array_column($items, 'external_id'));
    }

    public function test_a_page_that_is_not_an_events_collection_throws(): void
    {
        Http::fake(['*' => Http::response('<html>not json</html>', 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no "upcoming" list');

        (new SquarespaceEventsCollector)->fetch('https://example.test/events');
    }

    public function test_an_http_error_throws(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');

        (new SquarespaceEventsCollector)->fetch('https://example.test/events');
    }
}
