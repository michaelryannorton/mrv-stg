<?php

namespace Tests\Feature\Ingestion;

use App\Services\Ingestion\GrowthZoneCollector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/** The chamber calendar collector, against made-up pages in GrowthZone's layout. */
class GrowthZoneCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->travelTo('2026-11-27 20:00:00');
    }

    private function month(string ...$links): string
    {
        return '<html><body>'.implode('', array_map(fn ($l) => '<a href="'.$l.'">Event</a>', $links)).'</body></html>';
    }

    private function detail(string $name, string $start, string $end, string $location = '', string $time = ''): string
    {
        return <<<HTML
        <html><body><div class="gz-event-details" itemscope itemtype="http://schema.org/Event">
          <div class="row gz-banner"><div class="mn-banner">Sponsor of the moment</div></div>
          <meta itemprop="eventStatus" content="EventScheduled">
          <h1 class="gz-pagetitle" itemprop="name">{$name}</h1>
          <span itemprop="startDate" content="{$start}">a day</span><meta itemprop="endDate" content="{$end}" />
          {$time}
          {$location}
          <div class="row gz-event-description" itemprop="about"><div><h3 class="gz-subtitle">Description</h3>
            <p>Coffee and <b>introductions</b>.</p><p><a href="https://example.org/rsvp">RSVP</a></p></div></div>
          <p><span itemprop="organizer">A Person</span> <a href="mailto:person@example.org">Send Email</a></p>
          <form><input name="token" value="one-off"></form><script>var x = 1;</script>
        </div></body></html>
        HTML;
    }

    private function place(): string
    {
        return '<div itemprop="location" itemscope itemtype="http://schema.org/Place"><h5>Location</h5>
            <p><span itemprop="name"><span>Conference Center<br />12603 Mariposa Rd.<br />Victorville, CA 92395</span></span></p>
            <a itemprop="address" itemscope itemtype="http://schema.org/PostalAddress"><span itemprop="streetAddress">12603 Mariposa Rd.</span>
            <span itemprop="addressLocality">Victorville</span> <span itemprop="addressRegion">CA</span> <span itemprop="postalCode">92395</span></a></div>';
    }

    private function fakeCalendar(array $details): void
    {
        Http::fake(function (Request $request) use ($details) {
            $url = $request->url();

            if (str_contains($url, '/calendar/2026-11-01')) {
                return Http::response($this->month(...array_map(fn ($slug) => "https://chamber.test/events/details/{$slug}?calendarMonth=2026-11-01", array_keys($details))));
            }

            if (str_contains($url, '/calendar/')) {
                // The same event listed again, relative this time, and a link to another site.
                return Http::response($this->month('/events/details/'.array_key_first($details), 'https://elsewhere.test/events/details/other-9'));
            }

            $body = $details[basename(parse_url($url, PHP_URL_PATH))] ?? null;

            return $body === null ? Http::response('', 404) : Http::response($body);
        });
    }

    public function test_reads_an_event_from_its_own_page(): void
    {
        $this->fakeCalendar(['coffee-break-5416' => $this->detail('Coffee Break', '2026-12-02T15:30:00Z', '2026-12-02T17:00:00Z', $this->place(), '<span class="gz-details-time">7:30 AM - 9:00 AM PST</span>')]);

        $items = (new GrowthZoneCollector)->fetch('https://chamber.test/events');

        $this->assertCount(1, $items);
        $item = $items[0];
        $this->assertSame('5416', $item['external_id']);
        $this->assertSame('Coffee Break', $item['title']);
        $this->assertSame('2026-12-02 07:30 America/Los_Angeles', $item['start_at']->format('Y-m-d H:i e'));
        $this->assertSame('2026-12-02 09:00', $item['end_at']->format('Y-m-d H:i'));
        $this->assertFalse($item['all_day']);
        $this->assertSame('Conference Center, 12603 Mariposa Rd., Victorville, CA 92395', $item['location']);
        $this->assertSame('https://chamber.test/events/details/coffee-break-5416', $item['url']);
        $this->assertSame("Coffee and introductions.\n\nRSVP (https://example.org/rsvp)", $item['description']);
        $this->assertStringContainsString('mailto:person@example.org', $item['raw']);
        $this->assertStringContainsString('EventScheduled', $item['raw']);
        $this->assertStringNotContainsString('Sponsor of the moment', $item['raw']);
        $this->assertStringNotContainsString('one-off', $item['raw']);
        $this->assertStringNotContainsString('var x', $item['raw']);
    }

    public function test_an_entry_with_no_time_line_that_starts_at_midnight_is_all_day(): void
    {
        $this->fakeCalendar(['holiday-7' => $this->detail('Office closed', '2026-12-24T08:00:00Z', '2026-12-26T07:59:00Z')]);

        $item = (new GrowthZoneCollector)->fetch('https://chamber.test/events')[0];

        $this->assertTrue($item['all_day']);
        $this->assertSame('2026-12-24', $item['start_at']->toDateString());
        $this->assertSame('2026-12-25', $item['end_at']->toDateString());
    }

    public function test_reads_each_month_of_the_window_and_each_event_once(): void
    {
        $this->fakeCalendar(['coffee-break-5416' => $this->detail('Coffee Break', '2026-12-02T15:30:00Z', '2026-12-02T17:00:00Z')]);

        (new GrowthZoneCollector)->fetch('https://chamber.test/events');

        $urls = collect(Http::recorded())->map(fn ($pair) => $pair[0]->url());
        $this->assertSame(7, $urls->filter(fn ($u) => str_contains($u, '/calendar/'))->count());
        $this->assertSame(1, $urls->filter(fn ($u) => str_contains($u, '/details/'))->count());
        $this->assertFalse($urls->contains(fn ($u) => str_contains($u, 'elsewhere.test')));
    }

    public function test_leaves_out_events_that_are_over_or_beyond_the_window(): void
    {
        $this->fakeCalendar([
            'over-1' => $this->detail('Over', '2026-11-10T15:00:00Z', '2026-11-10T16:00:00Z'),
            'soon-2' => $this->detail('Soon', '2026-12-10T15:00:00Z', '2026-12-10T16:00:00Z'),
            'far-3' => $this->detail('Far', '2027-09-10T15:00:00Z', '2027-09-10T16:00:00Z'),
        ]);

        $this->assertSame(['Soon'], array_column((new GrowthZoneCollector)->fetch('https://chamber.test/events'), 'title'));
    }

    public function test_a_calendar_with_no_event_links_is_an_error(): void
    {
        Http::fake(['*' => Http::response($this->month())]);

        $this->expectException(RuntimeException::class);
        (new GrowthZoneCollector)->fetch('https://chamber.test/events');
    }

    public function test_a_calendar_whose_event_pages_mostly_fail_is_an_error(): void
    {
        $this->fakeCalendar(['a-1' => '<html><body>No markup</body></html>']);

        $this->expectException(RuntimeException::class);
        (new GrowthZoneCollector)->fetch('https://chamber.test/events');
    }
}
