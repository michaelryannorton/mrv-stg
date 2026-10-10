<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A curated slice of the calendar (see community/Community Phase 2 Slice 1 Plan.md, section 6).
 * Membership is computed, not stored: Event::scopeInChannel() combines the linked categories with
 * the explicit rows in event_channels.
 */
class Channel extends Model
{
    use HasUuid, SoftDeletes;

    public const STATUSES = ['draft', 'active', 'inactive', 'archived'];

    protected $fillable = ['uuid', 'name', 'slug', 'description', 'short_description', 'status', 'sort_order'];

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'channel_categories');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CuratorAssignment::class);
    }

    /** Events with an explicit row in this channel (curated, featured or excluded), not the computed membership. */
    public function eventPicks(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_channels')
            ->withPivot(['relationship_type', 'added_by_user_id'])
            ->withTimestamps();
    }

    /** Every event that is in this channel, by the computed membership rule. */
    public function events(): Builder
    {
        return Event::query()->inChannel($this);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
