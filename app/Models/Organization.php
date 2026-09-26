<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'uuid', 'name', 'slug', 'organization_type', 'description', 'website_url',
        'phone', 'email', 'logo_path', 'address_line_1', 'address_line_2', 'city',
        'state', 'postal_code', 'country_code', 'latitude', 'longitude', 'verification_status',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
        ];
    }

    public function venues(): HasMany
    {
        return $this->hasMany(Venue::class);
    }

    public function eventsOrganized(): HasMany
    {
        return $this->hasMany(Event::class, 'organizer_id');
    }

    public function eventSeries(): HasMany
    {
        return $this->hasMany(EventSeries::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }
}
