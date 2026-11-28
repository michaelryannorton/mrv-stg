<?php

namespace Tests\Feature\Ingestion;

use App\Models\Audience;
use App\Models\Event;
use App\Models\PlaceLookup;
use App\Models\Source;
use App\Models\Venue;
use App\Services\Ingestion\EventIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** Venues, cost and audience from feed records, and the coordinates looked up for them. */
class PlacesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();

        foreach (['Seniors', 'Families', 'Children', 'Adults', 'All Ages'] as $name) {
            Audience::firstOrCreate(['name' => $name], ['slug' => str($name)->slug()]);
        }
    }

    private function source(): Source
    {
        return Source::firstOrCreate(['name' => 'Library'], [
            'source_type' => 'api', 'collector_type' => 'tribe_events', 'feed_url' => 'https://example.test/api', 'access_scope' => 'public',
        ]);
    }

    private function item(array $venue = [], array $extra = [], string $id = 'a'): array
    {
        $venue = array_merge(['venue' => 'Branch Library', 'address' => '100 Main St', 'city' => 'Hesperia', 'state' => 'CA', 'zip' => '92345'], $venue);

        return [
            'external_id' => $id, 'title' => 'Story time', 'description' => null, 'start_at' => now()->addDay()->startOfHour(), 'end_at' => null,
            'all_day' => false, 'location' => $venue['venue'], 'url' => null, 'timezone' => 'America/Los_Angeles',
            'raw' => json_encode(['venue' => $venue, ...$extra]),
        ];
    }

    private function ingest(array ...$items): void
    {
        app(EventIngestor::class)->ingest($this->source(), $items);
    }

    private function geocoder(array ...$matches): void
    {
        $sequence = Http::sequence();

        foreach ($matches as $match) {
            $sequence->push(['result' => ['addressMatches' => $match === [] ? [] : [['coordinates' => ['x' => $match[1], 'y' => $match[0]], 'matchedAddress' => 'M']]]]);
        }

        Http::fake(['geocoding.geo.census.gov/*' => $sequence]);
    }

    public function test_a_new_event_gets_its_venue_cost_and_audience(): void
    {
        $this->ingest($this->item(extra: ['cost' => 'Free', 'categories' => [['name' => 'Families']]]));

        $event = Event::sole();
        $this->assertSame(['Branch Library', 'Hesperia', '100 Main St', '92345', 'unverified'], [
            $event->venue->name, $event->venue->city, $event->venue->address_line_1, $event->venue->postal_code, $event->venue->verification_status,
        ]);
        $this->assertTrue($event->is_free);
        $this->assertSame(['Families'], $event->audiences->pluck('name')->all());
    }

    public function test_the_same_place_is_one_venue_and_another_branch_is_another(): void
    {
        $this->ingest(
            $this->item(),
            $this->item(['venue' => 'branch  library', 'city' => 'hesperia'], id: 'b'),
            $this->item(['address' => '999 Other Rd'], id: 'c'),
            $this->item(['city' => 'Victorville'], id: 'd'),
        );

        $this->assertSame(3, Venue::count());
        $this->assertSame(Event::orderBy('id')->get()[0]->venue_id, Event::orderBy('id')->get()[1]->venue_id);
    }

    public function test_an_existing_venue_is_matched_and_only_its_blanks_filled(): void
    {
        $venue = Venue::create(['name' => 'Branch Library', 'slug' => 'b', 'city' => 'Hesperia', 'postal_code' => '90000']);

        $this->ingest($this->item());

        $this->assertSame($venue->id, Event::sole()->venue_id);
        $this->assertSame(['100 Main St', '90000'], [$venue->fresh()->address_line_1, $venue->fresh()->postal_code]);
    }

    public function test_when_the_feed_moves_an_event_its_own_venue_follows(): void
    {
        $this->ingest($this->item(extra: ['cost' => 'Free']));
        $this->ingest($this->item(['venue' => 'Town Hall', 'address' => '5 Hall St'], ['cost' => '$5']));

        $event = Event::sole()->fresh();
        $this->assertSame('Town Hall', $event->venue->name);
        $this->assertFalse($event->is_free);
        $this->assertSame('5.00', (string) $event->price_min);
    }

    public function test_a_venue_and_cost_a_person_chose_are_not_replaced(): void
    {
        $this->ingest($this->item(extra: ['cost' => 'Free']));
        $chosen = Venue::create(['name' => 'Chosen', 'slug' => 'chosen', 'city' => 'Adelanto']);
        Event::sole()->update(['venue_id' => $chosen->id, 'is_free' => false]);

        $this->ingest($this->item(['venue' => 'Town Hall', 'address' => '5 Hall St'], ['cost' => 'Free', 'x' => 1]));

        $this->assertSame($chosen->id, Event::sole()->venue_id);
        $this->assertFalse(Event::sole()->is_free);
    }

    public function test_an_unchanged_item_does_not_bring_back_a_cleared_venue(): void
    {
        $item = $this->item();
        $this->ingest($item);
        Event::sole()->update(['venue_id' => null]);

        $this->ingest($item);

        $this->assertNull(Event::sole()->venue_id);
    }

    public function test_online_events_share_one_online_venue(): void
    {
        $this->ingest($this->item(extra: ['is_virtual' => true]), $this->item(extra: ['is_virtual' => true], id: 'b'));

        $this->assertSame(1, Venue::count());
        $this->assertSame('online', Venue::sole()->venue_type);
    }

    public function test_places_are_looked_up_once(): void
    {
        $this->ingest($this->item(), $this->item(['venue' => 'Annex'], id: 'b'));
        $this->geocoder([34.42, -117.30]);

        $this->artisan('places:locate')->expectsOutput('Venues without coordinates: 2. Addresses asked: 1. Venues located: 2.')->assertSuccessful();
        $this->artisan('places:locate')->expectsOutput('Venues without coordinates: 0. Addresses asked: 0. Venues located: 0.')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r['address'] === '100 Main St, Hesperia, CA 92345');
        $this->assertSame(['34.420000', '-117.300000'], [(string) Venue::first()->latitude, (string) Venue::first()->longitude]);
    }

    public function test_an_unknown_address_is_not_asked_again_for_a_month(): void
    {
        $this->ingest($this->item());
        $this->geocoder([], [34.1, -117.1]);

        $this->artisan('places:locate')->assertSuccessful();
        $this->travel(29)->days();
        $this->artisan('places:locate')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertSame('not_matched', PlaceLookup::sole()->status);

        $this->travel(2)->days();
        $this->artisan('places:locate')->assertSuccessful();

        $this->assertSame('matched', PlaceLookup::sole()->status);
        $this->assertSame(2, PlaceLookup::sole()->attempts);
    }

    public function test_it_stops_when_the_geocoder_keeps_failing(): void
    {
        foreach (range(1, 5) as $n) {
            $this->ingest($this->item(['venue' => "Place {$n}", 'address' => "{$n} Road"], id: (string) $n));
        }
        Http::fake(['*' => Http::response('', 429)]);

        $this->artisan('places:locate')->assertFailed();

        Http::assertSentCount(3);
        $this->assertSame(['error'], PlaceLookup::query()->distinct()->pluck('status')->all());
    }

    public function test_venues_without_a_street_number_and_coordinates_already_there_are_left_alone(): void
    {
        $this->ingest($this->item(['address' => 'Main Street']), $this->item(['venue' => 'Fixed', 'geo_lat' => 34.5, 'geo_lng' => -117.4], id: 'b'));
        Http::fake();

        $this->artisan('places:locate')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_filling_stored_events_is_a_dry_run_until_asked(): void
    {
        $this->ingest($this->item(extra: ['cost' => 'Free', 'categories' => [['name' => 'Seniors']]]));
        // As an event collected before places were read: a kept record and no values.
        Event::sole()->update(['venue_id' => null, 'is_free' => null]);
        Event::sole()->audiences()->detach();
        Venue::query()->forceDelete();

        $this->artisan('places:fill')
            ->expectsOutput('Events with a kept record: 1. Would be given a venue: 1, cost: 1, audiences: 1. Dry run: nothing changed.')
            ->assertSuccessful();
        $this->assertSame(0, Venue::count());
        $this->assertNull(Event::sole()->venue_id);

        $this->artisan('places:fill --apply')->expectsOutput('Events with a kept record: 1. Given a venue: 1, cost: 1, audiences: 1.')->assertSuccessful();

        $this->assertSame('Branch Library', Event::sole()->venue->name);
        $this->assertTrue(Event::sole()->is_free);
        $this->artisan('places:fill --apply')->expectsOutput('Events with a kept record: 1. Given a venue: 0, cost: 0, audiences: 0.')->assertSuccessful();
    }
}
