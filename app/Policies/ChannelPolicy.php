<?php

namespace App\Policies;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may curate a channel (Phase 2 slice 1c-ii). An administrator may curate every channel. Anyone
 * else needs the channels.curate permission and an active assignment to the channel. Everyone
 * else is answered "not found", so a curator cannot learn which channels exist (draft channels are
 * not public) by trying slugs.
 */
class ChannelPolicy
{
    public function curate(User $user, Channel $channel): Response|bool
    {
        if ($user->hasRole('administrator')) {
            return true;
        }

        if (! $user->can('channels.curate') || $channel->status === 'archived') {
            return Response::denyAsNotFound();
        }

        return $user->curatedChannels()->whereKey($channel->id)->exists() ? true : Response::denyAsNotFound();
    }
}
