<?php

namespace App\Services\Places;

use App\Models\Audience;
use App\Models\Event;
use App\Models\Venue;
use Illuminate\Support\Str;

/**
 * Gives an event the venue, cost and audience its feed states. A feed fills blanks. It also moves a
 * value it set itself when the feed's own statement changes (so a moved event does not keep its old
 * place); a value that differs from what the feed said before was chosen by a person and stays.
 */
class PlaceAssigner
{
    /**
     * @param  array<string, mixed>  $facts  FeedFacts::from() of the item now
     * @param  array<string, mixed>|null  $previous  of the same item before this change, if known
     * @return list<string> what was changed: venue, cost, audiences
     */
    public function apply(Event $event, array $facts, ?array $previous = null, bool $save = true): array
    {
        $changed = [];

        $venueId = $this->venueIdFor($facts, $save);

        if ($venueId !== null && $event->venue_id !== $venueId) {
            $feedSetIt = $previous !== null && $event->venue_id !== null && $event->venue_id === $this->venueIdFor($previous, false);

            if ($event->venue_id === null || $feedSetIt) {
                $event->venue_id = $venueId;
                $changed[] = 'venue';
            }
        }

        if ($facts['is_free'] !== null && $event->is_free !== $facts['is_free']) {
            $feedSetIt = $previous !== null && $previous['is_free'] !== null && $event->is_free === $previous['is_free'];

            if ($event->is_free === null || $feedSetIt) {
                $event->is_free = $facts['is_free'];
                $event->price_min = $facts['price_min'];
                $event->price_max = $facts['price_max'];
                $event->currency = $facts['price_min'] !== null ? 'USD' : null;
                $changed[] = 'cost';
            }
        }

        if ($save && $changed !== []) {
            $event->save();
        }

        if ($facts['audiences'] !== [] && ! $event->audiences()->exists()) {
            $changed[] = 'audiences';

            if ($save) {
                $event->audiences()->sync(Audience::whereIn('name', $facts['audiences'])->pluck('id'));
            }
        }

        return $changed;
    }

    /**
     * The venue for a stated place, matched by name and city and otherwise created, marked
     * unverified. With $create false, only an existing one; -1 stands for "would be created".
     */
    private function venueIdFor(array $facts, bool $create): ?int
    {
        if ($facts['online']) {
            return $this->online($create);
        }

        $place = $facts['place'];

        if ($place === null) {
            return null;
        }

        $venue = Venue::query()
            ->when(
                $place['city'] !== null,
                fn ($q) => $q->whereRaw('lower(city) = ?', [mb_strtolower($place['city'])]),
                fn ($q) => $q->whereNull('city'),
            )
            ->get()
            ->first(fn (Venue $venue) => Address::compare($venue->name) === Address::compare($place['name'])
                // The same name at a different street address is another place (two branches), unless a
                // person has checked this venue: then the feed's way of writing the street does not matter.
                && ($venue->verification_status === 'verified' || $venue->address_line_1 === null || $place['street'] === null
                    || Address::compare($venue->address_line_1) === Address::compare($place['street'])));

        if ($venue === null) {
            return $create ? $this->create($place)->id : -1;
        }

        if ($create) {
            $this->fillBlanks($venue, $place);
        }

        return $venue->id;
    }

    private function fillBlanks(Venue $venue, array $place): void
    {
        $values = [
            'address_line_1' => $place['street'], 'state' => $place['state'], 'postal_code' => $place['zip'],
            'latitude' => $place['lat'], 'longitude' => $place['lng'],
        ];

        $venue->fill(array_filter($values, fn ($value, $column) => $value !== null && blank($venue->{$column}), ARRAY_FILTER_USE_BOTH));

        if ($venue->isDirty()) {
            $venue->save();
        }
    }

    private function create(array $place): Venue
    {
        return Venue::create([
            'name' => $place['name'],
            'slug' => $this->slug($place['name'].' '.$place['city']),
            'address_line_1' => $place['street'],
            'city' => $place['city'],
            'state' => $place['state'],
            'postal_code' => $place['zip'],
            'country_code' => 'US',
            'latitude' => $place['lat'],
            'longitude' => $place['lng'],
            'verification_status' => 'unverified',
        ]);
    }

    /** One venue stands for "online", so such events can be filtered and are not looked up. */
    private function online(bool $create): int
    {
        $venue = Venue::where('venue_type', 'online')->first();

        if ($venue === null && $create) {
            $venue = Venue::create(['name' => 'Online', 'slug' => $this->slug('online'), 'venue_type' => 'online', 'verification_status' => 'verified']);
        }

        return $venue?->id ?? -1;
    }

    private function slug(string $text): string
    {
        $base = Str::limit(Str::slug($text) ?: 'place', 200, '');
        $slug = $base;

        for ($n = 2; Venue::withTrashed()->where('slug', $slug)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }
}
