<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventSeriesProducer extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'legacy_relation_id', 'event_series_id', 'organization_id', 'venue_id', 'role', 'producer_raw',
    ];

    public function eventSeries(): BelongsTo
    {
        return $this->belongsTo(EventSeries::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }
}
