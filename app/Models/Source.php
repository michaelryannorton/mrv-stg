<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'uuid', 'legacy_source_id', 'organization_id', 'venue_id', 'name', 'source_type',
        'source_class', 'base_url', 'feed_url', 'geographic_scope', 'access_scope',
        'discovery_value', 'canonical_reliability', 'ingestion_friendliness',
        'collector_type', 'collector_config', 'poll_interval_minutes',
        'active', 'trust_level', 'last_checked_at', 'last_success_at', 'reliability_score',
        'last_error', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'collector_config' => 'array',
            'active' => 'boolean',
            'last_checked_at' => 'datetime',
            'last_success_at' => 'datetime',
            'reliability_score' => 'decimal:2',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function sourceRecords(): HasMany
    {
        return $this->hasMany(SourceRecord::class);
    }

    public function eventSources(): HasMany
    {
        return $this->hasMany(EventSource::class);
    }

    public function defaultCategories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'source_default_categories');
    }

    public function defaultTags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'source_default_tags');
    }

    /**
     * Whether an event ingested from this source can skip the review queue entirely — the
     * pre-approval mechanism from community/Community Events Calendar - Phase 1 Plan.md section 4.
     */
    public function autoPublishes(): bool
    {
        return $this->trust_level === 'auto_publish';
    }
}
