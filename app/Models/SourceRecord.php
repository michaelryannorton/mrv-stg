<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceRecord extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'source_records';

    protected $fillable = [
        'uuid', 'source_id', 'external_id', 'source_url', 'record_type', 'raw_payload',
        'raw_text', 'content_hash', 'observed_at', 'processed_at', 'processing_status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'observed_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function eventSources(): HasMany
    {
        return $this->hasMany(EventSource::class);
    }
}
