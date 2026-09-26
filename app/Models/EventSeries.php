<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventSeries extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $table = 'event_series';

    protected $fillable = [
        'uuid', 'title', 'slug', 'description', 'organization_id', 'venue_id',
        'recurrence_rule', 'status',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'event_series_id');
    }
}
