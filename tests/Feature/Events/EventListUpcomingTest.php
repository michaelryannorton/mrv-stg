<?php

namespace Tests\Feature\Events;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The public list shows what is coming (Michael, 2026-11-27); the calendar views keep the past. */
class EventListUpcomingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-12-15 20:00:00'); // 12:00 Pacific, mid-month, so every date below is in one month grid
    }

    private function event(string $title, string $start, ?string $end = null): void
    {
        Event::create([
            'title' => $title, 'slug' => Str::random(10), 'start_at' => $start, 'end_at' => $end, 'timezone' => 'America/Los_Angeles',
            'status' => 'scheduled', 'editorial_status' => 'published',
        ]);
    }

    private function titles(array $query, string $key): array
    {
        $titles = [];
        $this->get(route('events.index', $query))->assertInertia(function (Assert $page) use (&$titles, $key) {
            $titles = array_column(data_get($page->toArray()['props'], $key), 'title');
        });

        return $titles;
    }

    public function test_the_list_leaves_out_events_that_are_over_and_the_month_view_keeps_them(): void
    {
        $this->event('Over', '2026-12-10 18:00:00');
        $this->event('Over with an end', '2026-12-08 18:00:00', '2026-12-09 18:00:00');
        $this->event('Still running', '2026-12-08 18:00:00', '2026-12-20 18:00:00');
        $this->event('Coming', '2026-12-18 18:00:00');

        $this->assertSame(['Still running', 'Coming'], $this->titles([], 'events.data'));
        $this->assertEqualsCanonicalizing(
            ['Over', 'Over with an end', 'Still running', 'Coming'],
            $this->titles(['view' => 'month', 'date' => '2026-12-15'], 'events'),
        );
    }
}
