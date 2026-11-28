<?php

namespace Tests\Feature\Moderation;

use App\Models\Event;
use App\Models\EventSource;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The review queue's tabs and rows (Michael, 2026-11-27: upcoming only, one row per series, nothing publishes itself). */
class ReviewQueueTest extends TestCase
{
    use RefreshDatabase;

    private Source $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create()->assignRole('administrator'));
        $this->feed = $this->source('Startup Calendar', 'ics');
    }

    private function source(string $name, string $collector): Source
    {
        return Source::create(['name' => $name, 'source_type' => 'ics', 'collector_type' => $collector, 'active' => true]);
    }

    private function event(string $title, int $days, ?Source $source = null, array $attributes = []): Event
    {
        $event = Event::create([
            'title' => $title, 'slug' => Str::random(10), 'start_at' => now()->addDays($days), 'timezone' => 'America/Los_Angeles',
            'status' => 'candidate', 'editorial_status' => 'pending_review', ...$attributes,
        ]);

        if ($source) {
            EventSource::create([
                'event_id' => $event->id, 'source_id' => $source->id, 'external_id' => Str::random(8),
                'first_seen_at' => now(), 'last_seen_at' => now(), 'is_primary' => true,
            ]);
        }

        return $event;
    }

    private function rows(string $status = 'pending'): array
    {
        $rows = null;
        $this->get(route('admin.events.index', ['status' => $status]))->assertInertia(function (Assert $page) use (&$rows) {
            $rows = $page->toArray()['props']['rows'];
        });

        return $rows;
    }

    public function test_the_dates_of_one_feeds_title_are_one_row_soonest_first(): void
    {
        $second = $this->event('Open  Coffee', 9, $this->feed, ['description' => '<p>Bring a mug</p>']);
        $first = $this->event('open coffee', 2, $this->feed, ['description' => '<p>Bring a mug</p>']);
        $this->event('Another thing', 5, $this->feed);
        $this->event('Open Coffee', 3, $this->source('Other feed', 'tribe_events'));

        $rows = $this->rows();

        $this->assertSame(['open coffee', 'Open Coffee', 'Another thing'], array_column($rows, 'title'));
        $this->assertSame([$first->id, $second->id], array_column($rows[0]['dates'], 'id'));
        $this->assertSame(['Bring a mug'], $rows[0]['descriptions']);
        $this->assertFalse($rows[0]['descriptions_differ']);
        $this->assertFalse($rows[0]['places_differ']);
    }

    public function test_a_row_is_marked_when_its_dates_differ_in_place_or_description(): void
    {
        $this->event('Class', 1, $this->feed, ['description' => 'Week one', 'location_name_override' => 'Hall A']);
        $this->event('Class', 8, $this->feed, ['description' => 'Week two', 'location_name_override' => 'Hall B']);

        $row = $this->rows()[0];

        $this->assertTrue($row['places_differ']);
        $this->assertTrue($row['descriptions_differ']);
        $this->assertSame('Week two', $row['descriptions'][$row['dates'][1]['description']]);
        $this->assertSame('Hall B', $row['dates'][1]['place']);
    }

    public function test_events_that_did_not_come_from_a_feed_are_not_grouped(): void
    {
        $submissions = $this->source('Public submissions', 'manual');
        $this->event('Yard sale', 1, $submissions);
        $this->event('Yard sale', 2, $submissions);
        $this->event('Yard sale', 3);
        $this->event('Yard sale', 4);

        $this->assertCount(4, $this->rows());
    }

    public function test_pending_events_that_are_over_move_to_past_and_are_not_changed(): void
    {
        $over = $this->event('Over', -3, $this->feed);
        $overWithEnd = $this->event('Over with end', -5, $this->feed, ['end_at' => now()->subDays(4)]);
        $running = $this->event('Still running', -5, $this->feed, ['end_at' => now()->addDays(4)]);
        $this->event('Coming', 2, $this->feed);

        $this->assertSame(['Still running', 'Coming'], array_column($this->rows(), 'title'));
        $this->assertSame(['Over', 'Over with end'], array_column($this->rows('past'), 'title'));
        $this->get(route('admin.events.index'))->assertInertia(fn (Assert $page) => $page->where('counts.pending', 2)->where('counts.past', 2));

        foreach ([$over, $overWithEnd, $running] as $event) {
            $this->assertSame('pending_review', $event->fresh()->editorial_status);
        }
    }

    public function test_a_new_date_of_an_approved_series_stays_pending_and_is_marked(): void
    {
        $this->event('Open Coffee', 1, $this->feed, ['editorial_status' => 'published', 'status' => 'scheduled']);
        $new = $this->event('Open Coffee', 8, $this->feed);
        $this->event('Brews', 3, $this->feed);
        $this->event('Open Coffee', 4, $this->source('Other feed', 'tribe_events'));

        $marked = collect($this->rows())->where('approved_before', true);

        $this->assertSame([[$new->id]], $marked->map(fn ($row) => array_column($row['dates'], 'id'))->values()->all());
        $this->assertSame('pending_review', $new->fresh()->editorial_status);
    }

    public function test_approving_a_rows_dates_publishes_those_and_no_others(): void
    {
        $listed = [$this->event('Open Coffee', 1, $this->feed), $this->event('Open Coffee', 8, $this->feed)];
        $ids = array_column($this->rows()[0]['dates'], 'id');
        // A date the feed adds after the page was loaded is not part of what was approved.
        $later = $this->event('Open Coffee', 15, $this->feed);

        $this->post(route('admin.events.bulk'), ['action' => 'approve', 'ids' => $ids])->assertSessionHas('success', '2 events approved and published.');

        foreach ($listed as $event) {
            $this->assertSame('published', $event->fresh()->editorial_status);
        }
        $this->assertSame('pending_review', $later->fresh()->editorial_status);
        $this->assertTrue($this->rows()[0]['approved_before']);
    }

    public function test_the_tab_is_not_cut_at_a_thousand_events(): void
    {
        $row = ['start_at' => now()->addDay(), 'timezone' => 'America/Los_Angeles', 'status' => 'candidate', 'editorial_status' => 'pending_review'];

        foreach (array_chunk(range(1, 1005), 100) as $chunk) {
            Event::query()->insert(array_map(fn (int $i) => $row + ['uuid' => (string) Str::uuid(), 'title' => "E{$i}", 'slug' => "e-{$i}"], $chunk));
        }

        $this->assertCount(1005, $this->rows());
    }
}
