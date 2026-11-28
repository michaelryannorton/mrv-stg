<?php

namespace Tests\Unit;

use App\Services\Places\Address;
use App\Services\Places\FeedFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** What a feed's record is read as saying about place, cost and audience. */
class FeedFactsTest extends TestCase
{
    private function tribe(array $event, ?string $location = null): array
    {
        return FeedFacts::from('tribe_events', json_encode($event), $location);
    }

    public function test_a_wordpress_venue_is_read(): void
    {
        $facts = $this->tribe(['venue' => [
            'venue' => 'Hesperia Branch Library', 'address' => '9650 7th Ave., Hesperia, CA', 'city' => 'HESPERIA', 'state' => 'California', 'zip' => '92345-1234',
        ]]);

        $this->assertSame([
            'name' => 'Hesperia Branch Library', 'street' => '9650 7th Ave.', 'city' => 'Hesperia', 'state' => 'CA', 'zip' => '92345', 'lat' => null, 'lng' => null,
        ], $facts['place']);
        $this->assertFalse($facts['online']);
    }

    public function test_feed_coordinates_are_kept_and_impossible_ones_are_not(): void
    {
        $near = $this->tribe(['venue' => ['venue' => 'A', 'city' => 'Hesperia', 'geo_lat' => 34.42, 'geo_lng' => -117.30]]);
        $far = $this->tribe(['venue' => ['venue' => 'A', 'city' => 'Hesperia', 'geo_lat' => 0, 'geo_lng' => 0]]);

        $this->assertSame([34.42, -117.3], [$near['place']['lat'], $near['place']['lng']]);
        $this->assertNull($far['place']['lat']);
    }

    public static function costs(): array
    {
        return [
            'free' => ['Free', true, null, null],
            'zero' => ['$0', true, null, null],
            'one price' => ['$5', false, 5.0, 5.0],
            'a range' => ['$5 – $10.50', false, 5.0, 10.5],
            'free to some' => ['Free – $10', null, null, null],
            'words' => ['Donations welcome', null, null, null],
            'nothing' => ['', null, null, null],
        ];
    }

    #[DataProvider('costs')]
    public function test_cost_is_read_only_when_it_is_clear(string $cost, ?bool $free, ?float $min, ?float $max): void
    {
        $facts = $this->tribe(['cost' => $cost]);

        $this->assertSame([$free, $min, $max], [$facts['is_free'], $facts['price_min'], $facts['price_max']]);
    }

    public function test_audiences_come_from_category_names(): void
    {
        $named = fn (string ...$names) => $this->tribe(['categories' => array_map(fn ($n) => ['name' => $n], $names)])['audiences'];

        $this->assertSame(['Seniors'], $named('Seniors (65+)'));
        $this->assertSame(['Children'], $named('Kids Zone (6-11 years)'));
        $this->assertSame(['Families', 'All Ages'], $named('Families (all ages)'));
        $this->assertSame(['Adults'], $named('Adults &amp; Crafts'));
        $this->assertSame([], $named('Young Adults', 'Teens', 'Books'));
    }

    public function test_a_calendar_location_line_is_read(): void
    {
        $facts = FeedFacts::from('ics', null, 'Startup Hub, 15555 Main St, Suite 4, Hesperia, CA 92345, USA');

        $this->assertSame(['Startup Hub', '15555 Main St', 'Hesperia', 'CA', '92345'], [
            $facts['place']['name'], $facts['place']['street'], $facts['place']['city'], $facts['place']['state'], $facts['place']['zip'],
        ]);
    }

    public function test_an_address_with_no_venue_name_is_named_for_its_street(): void
    {
        $this->assertSame('17380 Darwin Ave.', FeedFacts::from('growthzone', null, '17380 Darwin Ave., Bldg E, Hesperia, CA 92345')['place']['name']);
    }

    public function test_calendar_coordinates_are_read_from_the_record(): void
    {
        $raw = "BEGIN:VEVENT\r\nX-APPLE-STRUCTURED-LOCATION;VALUE=URI;X-TITLE=Park:geo:34.5000,-117.\r\n 3000\r\nEND:VEVENT\r\n";

        $place = FeedFacts::from('ics', $raw, 'The Park, 1 Park Way, Victorville, CA 92392')['place'];

        $this->assertSame([34.5, -117.3], [$place['lat'], $place['lng']]);
    }

    public function test_a_chamber_record_gives_its_marked_up_address(): void
    {
        $raw = '<div><div itemprop="location"><span itemprop="name">Conference Center<br>12603 Mariposa Rd.</span><span itemprop="streetAddress">12603 Mariposa Rd.</span>'
            .'<span itemprop="addressLocality">Victorville</span><span itemprop="addressRegion">CA</span><span itemprop="postalCode">92395</span></div></div>';

        $place = FeedFacts::from('growthzone', $raw, 'Conference Center, 12603 Mariposa Rd., Victorville, CA 92395')['place'];

        $this->assertSame(['Conference Center', '12603 Mariposa Rd.', 'Victorville', '92395'], [$place['name'], $place['street'], $place['city'], $place['zip']]);
    }

    public function test_online_events_have_no_place(): void
    {
        foreach (['Zoom event, you will receive a link', 'Online', 'Virtual'] as $location) {
            $facts = FeedFacts::from('ics', null, $location);
            $this->assertTrue($facts['online'], $location);
            $this->assertNull($facts['place']);
        }

        $this->assertTrue($this->tribe(['is_virtual' => true, 'venue' => ['venue' => 'Library', 'city' => 'Hesperia']])['online']);
        // A street address wins over a word in the same line.
        $this->assertFalse(FeedFacts::from('ics', null, 'Virtual Reality Lounge, 12 Main St, Victorville, CA')['online']);
    }

    public function test_a_room_name_alone_is_not_a_place(): void
    {
        $this->assertNull(FeedFacts::from('ics', null, 'Community Room B')['place']);
    }

    public function test_cities_keep_their_capitals_unless_all_one_case(): void
    {
        $this->assertSame('Apple Valley', Address::city('apple valley'));
        $this->assertSame('Oro Grande', Address::city('ORO GRANDE'));
        $this->assertSame('McFarland', Address::city('McFarland'));
        $this->assertNull(Address::city('92345'));
    }
}
