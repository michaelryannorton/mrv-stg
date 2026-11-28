<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlaceLookup extends Model
{
    protected $fillable = [
        'lookup_key', 'street', 'city', 'state', 'postal_code', 'status', 'latitude', 'longitude',
        'matched_address', 'provider', 'attempts', 'looked_up_at',
    ];

    protected function casts(): array
    {
        return ['latitude' => 'decimal:6', 'longitude' => 'decimal:6', 'looked_up_at' => 'datetime'];
    }
}
