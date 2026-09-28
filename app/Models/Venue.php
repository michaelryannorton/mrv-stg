<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Venue extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'uuid', 'legacy_entity_id', 'organization_id', 'name', 'slug', 'description', 'address_line_1',
        'address_line_2', 'city', 'state', 'postal_code', 'country_code', 'latitude',
        'longitude', 'timezone', 'website_url', 'phone', 'venue_type', 'verification_status',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function eventSeries(): HasMany
    {
        return $this->hasMany(EventSeries::class);
    }
}
