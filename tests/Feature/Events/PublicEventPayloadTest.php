<?php

namespace Tests\Feature\Events;

use App\Models\Category;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What /events sends to the browser. The page used to receive whole event models, including
 * moderation and ingestion columns; it now gets the explicit field list in PublicEventCard, so a
 * column added to the events table later stays private until someone adds it there on purpose.
 */
class PublicEventPayloadTest extends TestCase
{
    use RefreshDatabase;

    private const CARD_KEYS = [
        'all_day', 'audiences', 'categories', 'details_url', 'end_at', 'id', 'is_free', 'organizer',
        'short_description', 'slug', 'start_at', 'tags', 'timezone', 'title', 'uuid', 'venue',
    ];

    /** An event with every internal column filled in, so a leak shows up as a key or a value. */
    private function loadedEvent(): Event
    {
        $unique = Str::lower(Str::random(6));
        $venue = Venue::create([
            'name' => 'Civic Hall', 'slug' => 'civic-hall-'.$unique, 'city' => 'Victorville', 'address_line_1' => 'LEAK-VENUE-ADDRESS',
            'phone' => 'LEAK-VENUE-PHONE', 'latitude' => 34.5, 'longitude' => -117.3,
        ]);
        $organizer = Organization::create([
            'name' => 'Town Group', 'slug' => 'town-group-'.$unique, 'email' => 'leak-org@example.test', 'phone' => 'LEAK-ORG-PHONE',
        ]);

        $event = Event::create([
            'title' => 'Payload check',
            'slug' => 'payload-check-'.Str::random(4),
            'short_description' => 'Short and public',
            'description' => 'LEAK-FULL-DESCRIPTION',
            'start_at' => now('America/Los_Angeles')->addDay()->setTime(12, 0),
            'timezone' => 'America/Los_Angeles',
            'venue_id' => $venue->id,
            'organizer_id' => $organizer->id,
            'location_name_override' => 'LEAK-LOCATION-OVERRIDE',
            'address_override' => 'LEAK-ADDRESS-OVERRIDE',
            'latitude' => 34.1,
            'longitude' => -117.1,
            'canonical_url' => 'https://example.org/payload',
            'ticket_url' => 'https://example.org/LEAK-TICKETS',
            'price_min' => 12.5,
            'accessibility_notes' => 'LEAK-ACCESSIBILITY',
            'primary_image_path' => 'LEAK-IMAGE-PATH',
            'status' => 'published',
            'editorial_status' => 'published',
            'verification_status' => 'LEAK-VERIFICATION',
            'access_scope' => 'public',
            'overridden_fields' => ['title', 'LEAK-OVERRIDDEN'],
            'stale_fields' => ['LEAK-STALE'],
        ]);
        $event->categories()->attach(Category::firstOrCreate(['slug' => 'arts'], ['name' => 'Arts'])->id);

        return $event;
    }

    public static function views(): array
    {
        return ['list' => ['list'], 'day' => ['day'], 'week' => ['week'], 'month' => ['month']];
    }

    #[DataProvider('views')]
    public function test_every_view_sends_exactly_the_card_fields_and_nothing_internal(string $view): void
    {
        $this->loadedEvent();
        $captured = null;

        $response = $this->get(route('events.index', ['view' => $view, 'date' => now('America/Los_Angeles')->addDay()->toDateString()]));
        $response->assertInertia(function (Assert $page) use (&$captured, $view) {
            $props = $page->toArray()['props'];
            $captured = $view === 'list' ? $props['events']['data'] : $props['events'];
        });

        $this->assertCount(1, $captured);
        $keys = array_keys($captured[0]);
        sort($keys);
        $this->assertSame(self::CARD_KEYS, $keys);

        $this->assertSame(['city', 'id', 'name'], collect(array_keys($captured[0]['venue']))->sort()->values()->all());
        $this->assertSame(['id', 'name'], collect(array_keys($captured[0]['organizer']))->sort()->values()->all());
        $this->assertSame(['id', 'name', 'slug'], collect(array_keys($captured[0]['categories'][0]))->sort()->values()->all());

        $page = json_encode($response->viewData('page'));
        foreach (['LEAK', 'overridden_fields', 'stale_fields', 'editorial_status', 'access_scope', 'verification_status', 'created_by_user_id', 'leak-org@example.test'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $page, "The page payload contains {$forbidden}");
        }
    }

    public function test_the_public_values_still_arrive(): void
    {
        $this->loadedEvent();

        $this->get(route('events.index'))->assertInertia(fn (Assert $page) => $page
            ->where('events.data.0.title', 'Payload check')
            ->where('events.data.0.short_description', 'Short and public')
            ->where('events.data.0.details_url', 'https://example.org/payload')
            ->where('events.data.0.venue.name', 'Civic Hall')
            ->where('events.data.0.venue.city', 'Victorville')
            ->where('events.data.0.organizer.name', 'Town Group')
            ->where('events.data.0.categories.0.slug', 'arts'));
    }

    public function test_the_list_keeps_its_pagination_links_and_filters(): void
    {
        $this->loadedEvent();

        $this->get(route('events.index', ['category' => 'arts']))->assertInertia(fn (Assert $page) => $page
            ->has('events.data', 1)
            ->has('events.links')
            ->where('events.total', 1)
            ->where('filters.category', 'arts'));
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_events(): void
    {
        $this->loadedEvent();
        $this->get(route('events.index')); // warm one-time caches before counting

        $count = function (): int {
            $queries = 0;
            DB::listen(function () use (&$queries) {
                $queries++;
            });
            $this->get(route('events.index'))->assertOk();

            return $queries;
        };

        $few = $count();
        for ($i = 0; $i < 6; $i++) {
            $this->loadedEvent();
        }
        $this->assertSame($few, $count());
    }
}
