<?php

namespace App\Services\Places;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The US Census Bureau's public geocoder: a street address in, coordinates out. No account or key.
 * Positions are estimated along the street. Pages that show them carry the notice the Census API
 * terms ask for. Only a place's address is sent.
 */
class CensusGeocoder
{
    private const URL = 'https://geocoding.geo.census.gov/geocoder/locations/onelineaddress';

    /**
     * @return array{latitude: float, longitude: float, matched_address: ?string}|null null when the
     *                                                                                 address is not known to the geocoder
     *
     * @throws RuntimeException when the service could not be asked; the caller stops or retries later
     */
    public function locate(string $address): ?array
    {
        $response = Http::timeout(30)->get(self::URL, ['address' => $address, 'benchmark' => 'Public_AR_Current', 'format' => 'json']);

        if (! $response->successful()) {
            throw new RuntimeException("Geocoder failed with HTTP {$response->status()}");
        }

        $matches = $response->json('result.addressMatches');

        if (! is_array($matches)) {
            throw new RuntimeException('Geocoder answered without a result.');
        }

        $point = $matches[0]['coordinates'] ?? null;

        if (! isset($point['x'], $point['y'])) {
            return null;
        }

        return [
            'latitude' => round((float) $point['y'], 6),
            'longitude' => round((float) $point['x'], 6),
            'matched_address' => $matches[0]['matchedAddress'] ?? null,
        ];
    }
}
