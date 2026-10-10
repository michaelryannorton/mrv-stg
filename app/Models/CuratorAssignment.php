<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CuratorAssignment extends Model
{
    public const ROLES = ['lead', 'curator', 'contributor'];

    protected $fillable = ['channel_id', 'user_id', 'role', 'status', 'started_at', 'ended_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The one definition of who may be named on a public channel page: an active assignment, whose
     * holder still has the channels.curate permission (so removing the role removes the card), and
     * whose profile is public with a display name. Never rely on the profile alone.
     */
    public function scopePubliclyListed(Builder $query): Builder
    {
        return $query->where('curator_assignments.status', 'active')
            ->whereHas('user', fn (Builder $user) => $user->permission('channels.curate'))
            ->whereHas('user.curatorProfile', fn (Builder $profile) => $profile->where('is_public', true)->where('display_name', '!=', ''));
    }

    /** Lead first, then curators, then contributors; longest-serving first within a role. */
    public function scopeByRank(Builder $query): Builder
    {
        return $query->orderByRaw("case curator_assignments.role when 'lead' then 0 when 'curator' then 1 else 2 end")
            ->orderBy('curator_assignments.started_at')
            ->orderBy('curator_assignments.id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
