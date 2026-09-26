<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id', 'source_id', 'source_record_id', 'external_id', 'source_url',
        'first_seen_at', 'last_seen_at', 'is_primary', 'confidence_score',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'is_primary' => 'boolean',
            'confidence_score' => 'decimal:2',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function sourceRecord(): BelongsTo
    {
        return $this->belongsTo(SourceRecord::class);
    }
}
