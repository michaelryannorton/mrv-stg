<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\ExternalUrl;
use Illuminate\Database\Eloquent\Builder;
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
        'source_class', 'technology_clues', 'base_url', 'feed_url', 'geographic_scope', 'access_scope',
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

    /** Active feeds a schedule collects: every ICS feed, and the others marked "scheduled". */
    public function scopeScheduled(Builder $query): void
    {
        $query->where('active', true)->where(fn (Builder $q) => $q->where('collector_type', 'ics')->orWhere('collector_config->scheduled', true));
    }

    /** How often a schedule collects this feed, in words, or null when nothing does. */
    public function schedule(): ?string
    {
        if (! $this->active || ! $this->isFeed()) {
            return null;
        }

        return match (true) {
            $this->collector_type === 'ics' => 'every 30 minutes',
            (bool) ($this->collector_config['scheduled'] ?? false) => 'every 6 hours',
            default => null,
        };
    }

    /** A source the app collects from; 'manual' sources (the imported directory, public submissions) are not feeds. */
    public function isFeed(): bool
    {
        return $this->collector_type !== 'manual';
    }

    /**
     * The feed's web page (base_url) when it is safe to link: where an event with no address of its
     * own sends a visitor. The feed address itself is never shown to the public.
     */
    public function webPage(): ?string
    {
        return ExternalUrl::web($this->base_url);
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
