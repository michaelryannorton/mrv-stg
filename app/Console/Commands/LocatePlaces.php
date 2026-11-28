<?php

namespace App\Console\Commands;

use App\Models\PlaceLookup;
use App\Models\Venue;
use App\Services\Places\Address;
use App\Services\Places\CensusGeocoder;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Gives coordinates to venues that have a street address and none, so the places of newly collected
 * events can be mapped. Runs apart from collection: a slow or unavailable geocoder never fails it.
 * Each address is asked once (place_lookups). A coordinate already on a venue, from a feed or typed
 * by a person, is never replaced.
 */
class LocatePlaces extends Command
{
    protected $signature = 'places:locate {--limit=100 : The most addresses to ask the geocoder in one run}';

    protected $description = 'Look up coordinates for venues that have a street address and no coordinates';

    /** After this many failures in a row the run stops: the service is down or refusing. */
    private const STOP_AFTER = 3;

    private const RETRY_ERROR_HOURS = 6;

    private const RETRY_UNMATCHED_DAYS = 30;

    private const PAUSE_MS = 300;

    public function handle(CensusGeocoder $geocoder): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $asked = $located = $failuresInARow = 0;

        $venues = Venue::query()
            ->whereNull('latitude')
            ->whereNotNull('address_line_1')
            ->where(fn ($q) => $q->whereNull('venue_type')->orWhere('venue_type', '!=', 'online'))
            ->orderBy('id')
            ->get()
            ->filter(fn (Venue $venue) => Address::hasNumber($venue->address_line_1) && ($venue->city !== null || $venue->postal_code !== null));

        foreach ($venues as $venue) {
            $parts = [$venue->address_line_1, $venue->city, $venue->state ?? 'CA', $venue->postal_code];
            $key = mb_substr(implode('|', array_map(fn ($part) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $part))), $parts)), 0, 400);
            $lookup = PlaceLookup::where('lookup_key', $key)->first();

            if ($lookup === null || $this->isDue($lookup)) {
                if ($asked >= $limit) {
                    continue;
                }

                if ($asked > 0) {
                    Sleep::for(self::PAUSE_MS)->milliseconds();
                }

                $asked++;

                try {
                    $found = $geocoder->locate(trim(sprintf('%s, %s, %s %s', ...$parts), ' ,'));
                    $values = $found === null
                        ? ['status' => 'not_matched', 'latitude' => null, 'longitude' => null, 'matched_address' => null]
                        : ['status' => 'matched', ...$found];
                    $failuresInARow = 0;
                } catch (Throwable $e) {
                    $this->error("  {$venue->name}: {$e->getMessage()}");
                    $values = ['status' => 'error'];
                    $failuresInARow++;
                }

                $lookup = PlaceLookup::updateOrCreate(['lookup_key' => $key], [
                    'street' => $parts[0], 'city' => $parts[1], 'state' => $parts[2], 'postal_code' => $parts[3],
                    'attempts' => ($lookup->attempts ?? 0) + 1, 'looked_up_at' => now(), ...$values,
                ]);
            }

            if ($lookup->status === 'matched') {
                $venue->update(['latitude' => $lookup->latitude, 'longitude' => $lookup->longitude]);
                $located++;
            }

            if ($failuresInARow >= self::STOP_AFTER) {
                $this->error('Stopping: the geocoder failed '.self::STOP_AFTER.' times in a row.');

                break;
            }
        }

        $this->info("Venues without coordinates: {$venues->count()}. Addresses asked: {$asked}. Venues located: {$located}.");

        return $failuresInARow >= self::STOP_AFTER ? self::FAILURE : self::SUCCESS;
    }

    private function isDue(PlaceLookup $lookup): bool
    {
        return match ($lookup->status) {
            'matched' => false,
            'error' => $lookup->looked_up_at->lt(now()->subHours(self::RETRY_ERROR_HOURS)),
            default => $lookup->looked_up_at->lt(now()->subDays(self::RETRY_UNMATCHED_DAYS)),
        };
    }
}
