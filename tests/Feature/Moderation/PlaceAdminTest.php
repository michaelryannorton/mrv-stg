<?php

namespace Tests\Feature\Moderation;

use App\Models\Audience;
use App\Models\Event;
use App\Models\Source;
use App\Models\User;
use App\Models\Venue;
use App\Services\Ingestion\EventIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The administrator's Places screen, and the place, cost and audience tools of the review queue. */
class PlaceAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrator');
    }

    private function venue(array $overrides = []): Venue
    {
        static $n = 0;
        $n++;

        return Venue::create([
            'name' => "Place {$n}", 'slug' => "place-{$n}", 'address_line_1' => '1 Main St', 'city' => 'Hesperia', 'state' => 'CA', ...$overrides,
        ]);
    }

    private function event(?Venue $venue, array $overrides = []): Event
    {
        static $n = 0;
        $n++;

        return Event::create([
            'title' => 'Event '.$n, 'slug' => 'event-'.$n, 'start_at' => now()->addDays(2), 'timezone' => 'America/Los_Angeles',
            'venue_id' => $venue?->id, 'status' => 'candidate', 'editorial_status' => 'pending_review', ...$overrides,
        ]);
    }

    private function form(array $overrides = []): array
    {
        return ['street' => '1 Main St', 'city' => 'Hesperia', 'state' => 'CA', 'postal_code' => '', 'latitude' => '', 'longitude' => '', ...$overrides];
    }

    public function test_the_screen_lists_places_of_upcoming_events(): void
    {
        $unmapped = $this->venue();
        $mapped = $this->venue(['latitude' => 34.4, 'longitude' => -117.3]);
        $noStreet = $this->venue(['address_line_1' => null]);
        $past = $this->venue();
        $online = $this->venue(['venue_type' => 'online']);
        foreach ([$unmapped, $mapped, $noStreet, $online] as $venue) {
            $this->event($venue);
        }
        $this->event($past, ['start_at' => now()->subWeek()]);

        $this->actingAs($this->admin())->get('/admin/places')->assertInertia(fn (Assert $page) => $page
            ->component('admin/places/index')
            ->has('places', 3)
            ->where('places', fn ($places) => collect($places)->pluck('state_of_map', 'id')->all()
                == [$unmapped->id => 'waiting', $noStreet->id => 'no_street', $mapped->id => 'mapped']));
    }

    public function test_a_corrected_address_clears_the_old_point_and_marks_the_place_checked(): void
    {
        $venue = $this->venue(['latitude' => 34.4, 'longitude' => -117.3]);

        $this->actingAs($this->admin())
            ->put("/admin/places/{$venue->id}", $this->form(['street' => '2 Main St', 'latitude' => '34.400000', 'longitude' => '-117.300000']))
            ->assertSessionHasNoErrors();

        $venue->refresh();
        $this->assertSame(['2 Main St', null, null, 'verified'], [$venue->address_line_1, $venue->latitude, $venue->longitude, $venue->verification_status]);
    }

    public function test_a_typed_point_is_kept(): void
    {
        $venue = $this->venue();

        $this->actingAs($this->admin())->put("/admin/places/{$venue->id}", $this->form(['street' => '3 New Rd', 'latitude' => '34.5', 'longitude' => '-117.4']));

        $this->assertSame(['34.500000', '-117.400000'], [(string) $venue->fresh()->latitude, (string) $venue->fresh()->longitude]);
    }

    public function test_one_number_alone_is_refused_and_others_cannot_edit(): void
    {
        $venue = $this->venue();

        $this->actingAs($this->admin())->put("/admin/places/{$venue->id}", $this->form(['latitude' => '34.5']))->assertSessionHasErrors('longitude');
        $this->actingAs(User::factory()->create()->assignRole('editor'))->put("/admin/places/{$venue->id}", $this->form())->assertForbidden();
    }

    public function test_a_checked_place_still_matches_the_feed_that_writes_its_street_differently(): void
    {
        $venue = $this->venue(['name' => 'Branch Library', 'address_line_1' => '100 Corrected Ave', 'verification_status' => 'verified']);
        $source = Source::create(['name' => 'Library', 'source_type' => 'api', 'collector_type' => 'tribe_events', 'access_scope' => 'public']);

        app(EventIngestor::class)->ingest($source, [[
            'external_id' => 'a', 'title' => 'Story time', 'description' => null, 'start_at' => now()->addDay(), 'end_at' => null, 'all_day' => false,
            'location' => 'Branch Library', 'url' => null, 'timezone' => 'America/Los_Angeles',
            'raw' => json_encode(['venue' => ['venue' => 'Branch Library', 'address' => 'Corner of Corrected and 1st', 'city' => 'Hesperia']]),
        ]]);

        $this->assertSame($venue->id, Event::sole()->venue_id);
        $this->assertSame('100 Corrected Ave', $venue->fresh()->address_line_1);
    }

    public function test_the_queue_row_carries_city_map_state_cost_and_audience(): void
    {
        $this->event($this->venue(['latitude' => 34.4, 'longitude' => -117.3]), ['is_free' => true]);
        $this->event($this->venue(['venue_type' => 'online', 'city' => null, 'address_line_1' => null]), ['start_at' => now()->addDays(3)]);

        $this->actingAs($this->admin())->get('/admin/events')->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.dates.0.city', 'Hesperia')
            ->where('rows.0.dates.0.mapped', true)
            ->where('rows.0.dates.0.is_free', true)
            ->where('rows.1.dates.0.online', true)
            ->where('rows.1.dates.0.mapped', false)
            ->has('rows.0.audiences')
            ->has('audiences'));
    }

    public function test_cost_and_audience_are_set_for_many_dates_and_what_is_left_alone_stays(): void
    {
        $audience = Audience::firstOrCreate(['name' => 'Families'], ['slug' => 'families']);
        $a = $this->event(null, ['price_min' => 5, 'is_free' => false]);
        $b = $this->event(null);

        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/events/details', ['ids' => [$a->id, $b->id], 'free' => 'yes', 'audience_ids' => []])->assertSessionHasNoErrors();

        $this->assertSame([true, true], [$a->fresh()->is_free, $b->fresh()->is_free]);
        $this->assertNull($a->fresh()->price_min);
        $this->assertSame(0, $a->audiences()->count());

        $this->actingAs($admin)->post('/admin/events/details', ['ids' => [$a->id], 'free' => null, 'audience_ids' => [$audience->id]]);

        $this->assertTrue($a->fresh()->is_free);
        $this->assertSame(['Families'], $a->audiences()->pluck('name')->all());
        $this->assertSame('pending_review', $a->fresh()->editorial_status);
    }
}
