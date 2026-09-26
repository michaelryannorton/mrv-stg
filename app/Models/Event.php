<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'uuid', 'event_series_id', 'title', 'slug', 'short_description', 'description',
        'start_at', 'end_at', 'timezone', 'all_day', 'venue_id', 'organizer_id',
        'location_name_override', 'address_override', 'latitude', 'longitude',
        'canonical_url', 'ticket_url', 'price_min', 'price_max', 'currency', 'is_free',
        'age_restriction', 'accessibility_notes', 'primary_image_path',
        'status', 'editorial_status', 'verification_status', 'created_by_user_id',
        'published_at', 'last_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'all_day' => 'boolean',
            'is_free' => 'boolean',
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
            'price_min' => 'decimal:2',
            'price_max' => 'decimal:2',
            'published_at' => 'datetime',
            'last_verified_at' => 'datetime',
        ];
    }

    /**
     * Eloquent stores whatever wall-clock numbers a Carbon object holds — it does not convert to
     * UTC first. Handing it a Carbon already in another timezone (e.g. Carbon::now('America/Los_Angeles'))
     * would silently store the wrong instant, since retrieval re-parses the stored string assuming
     * UTC. These mutators force a real UTC conversion at the boundary, so every caller (tinker, a
     * future ingestion job, the clipper, an admin form) is safe by default. A caller passing a plain
     * string with no timezone info should attach the correct source timezone explicitly first —
     * e.g. Carbon::parse($raw, $sourceTimezone) — since the mutator can't infer intent from a bare
     * string.
     */
    protected function setStartAtAttribute(mixed $value): void
    {
        $this->attributes['start_at'] = $value ? Carbon::parse($value)->utc() : null;
    }

    protected function setEndAtAttribute(mixed $value): void
    {
        $this->attributes['end_at'] = $value ? Carbon::parse($value)->utc() : null;
    }

    public function eventSeries(): BelongsTo
    {
        return $this->belongsTo(EventSeries::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organizer_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'event_categories');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'event_tags');
    }

    public function audiences(): BelongsToMany
    {
        return $this->belongsToMany(Audience::class, 'event_audiences');
    }

    public function eventSources(): HasMany
    {
        return $this->hasMany(EventSource::class);
    }

    public function publicSubmissions(): HasMany
    {
        return $this->hasMany(PublicSubmission::class);
    }

    /**
     * Publicly visible per community/reference/Community Information Platform - Database Schema
     * section 97: editorial_status = published AND status not in (draft, candidate).
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('editorial_status', 'published')
            ->whereNotIn('status', ['draft', 'candidate']);
    }
}
