<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlaceLookup;
use App\Models\Venue;
use App\Services\Places\Address;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administrator-only: the venues of upcoming events, for correcting an address or a map point. A
 * venue saved here is marked verified, so a feed that writes the address differently still matches it.
 */
class PlaceController extends Controller
{
    public function index(): Response
    {
        $places = Venue::query()
            ->where(fn ($q) => $q->whereNull('venue_type')->orWhere('venue_type', '!=', 'online'))
            ->whereHas('events', fn ($q) => $q->upcoming())
            ->withCount(['events as upcoming_count' => fn ($q) => $q->upcoming()])
            ->orderByRaw('latitude IS NOT NULL')
            ->orderBy('city')
            ->orderBy('name')
            ->get();

        $unmatched = PlaceLookup::where('status', 'not_matched')->pluck('street')->map(fn ($street) => Address::compare($street))->flip();

        return Inertia::render('admin/places/index', [
            'places' => $places->map(fn (Venue $venue) => [
                'id' => $venue->id,
                'name' => $venue->name,
                'street' => $venue->address_line_1,
                'city' => $venue->city,
                'state' => $venue->state,
                'postal_code' => $venue->postal_code,
                'latitude' => $venue->latitude,
                'longitude' => $venue->longitude,
                'upcoming_count' => $venue->upcoming_count,
                'state_of_map' => match (true) {
                    $venue->latitude !== null => 'mapped',
                    ! Address::hasNumber($venue->address_line_1) => 'no_street',
                    isset($unmatched[Address::compare($venue->address_line_1)]) => 'not_found',
                    default => 'waiting',
                },
            ]),
        ]);
    }

    public function update(Request $request, Venue $venue): RedirectResponse
    {
        abort_if($venue->venue_type === 'online', 404);

        $data = $request->validate(
            [
                'street' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:40',
                'postal_code' => 'nullable|string|max:20',
                'latitude' => 'nullable|required_with:longitude|numeric|between:-90,90',
                'longitude' => 'nullable|required_with:latitude|numeric|between:-180,180',
            ],
            [
                'latitude.required_with' => 'Give both numbers, or neither.',
                'longitude.required_with' => 'Give both numbers, or neither.',
            ],
        );

        $address = [
            'address_line_1' => Address::text($data['street'] ?? null),
            'city' => Address::text($data['city'] ?? null),
            'state' => Address::state($data['state'] ?? null),
            'postal_code' => Address::text($data['postal_code'] ?? null),
        ];
        $point = isset($data['latitude'], $data['longitude'])
            ? [round((float) $data['latitude'], 6), round((float) $data['longitude'], 6)]
            : [null, null];
        $before = $venue->latitude === null ? [null, null] : [(float) $venue->latitude, (float) $venue->longitude];

        $venue->fill($address);
        // A new address makes the old point wrong, unless a point was typed along with it.
        if ($venue->isDirty(['address_line_1', 'city', 'state', 'postal_code']) && $point === $before) {
            $point = [null, null];
        }
        $venue->fill(['latitude' => $point[0], 'longitude' => $point[1], 'verification_status' => 'verified'])->save();

        return back()->with('success', $point[0] === null
            ? "\"{$venue->name}\" saved. Its map point will be looked up within half an hour."
            : "\"{$venue->name}\" saved.");
    }
}
