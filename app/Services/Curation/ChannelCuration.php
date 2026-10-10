<?php

namespace App\Services\Curation;

use App\Models\Channel;
use App\Models\Event;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The curator's picks inside one channel (Phase 2 slice 1c-ii). event_channels holds one row per
 * (event, channel), so each action is a fixed transition of that single state:
 *
 *   feature    member (by category, or curated) -> featured
 *   unfeature  featured -> no row if a linked category still includes the event, otherwise curated
 *   exclude    member -> excluded (drops any featured or curated state)
 *   restore    excluded -> no row (the event is back only if a linked category includes it)
 *   add        non-member with no row -> curated
 *
 * A pick never publishes an event; the public site shows only published events whatever is picked.
 * Callers have already authorized the user for the channel (ChannelPolicy::curate).
 */
class ChannelCuration
{
    public function stateOf(Channel $channel, Event $event): ?string
    {
        return $event->channelPicks()->where('channels.id', $channel->id)->first()?->pivot->relationship_type;
    }

    public function feature(Channel $channel, Event $event, User $by): string
    {
        $this->requireMember($channel, $event);
        $this->setState($channel, $event, 'featured', $by);

        return "Featured \"{$event->title}\".";
    }

    public function unfeature(Channel $channel, Event $event, User $by): string
    {
        $this->requireState($channel, $event, 'featured', 'This event is not featured.');

        $this->matchesCategories($channel, $event)
            ? $this->setState($channel, $event, null, $by)
            : $this->setState($channel, $event, 'curated', $by);

        return "Removed \"{$event->title}\" from the featured picks.";
    }

    public function exclude(Channel $channel, Event $event, User $by): string
    {
        $this->requireMember($channel, $event);
        $this->setState($channel, $event, 'excluded', $by);

        return "Excluded \"{$event->title}\" from this channel.";
    }

    public function restore(Channel $channel, Event $event, User $by): string
    {
        $this->requireState($channel, $event, 'excluded', 'This event is not excluded.');
        $this->setState($channel, $event, null, $by);

        return "Restored \"{$event->title}\".";
    }

    public function add(Channel $channel, Event $event, User $by): string
    {
        if ($this->stateOf($channel, $event) !== null) {
            abort(422, 'This event already has a pick in this channel. Restore it from the Excluded tab.');
        }
        if ($channel->events()->whereKey($event->id)->exists()) {
            abort(422, 'This event is already in the channel.');
        }

        $this->setState($channel, $event, 'curated', $by);

        return "Added \"{$event->title}\" to this channel.";
    }

    /** @throws HttpException 404 when the event is not in the channel, so it cannot be probed by id. */
    private function requireMember(Channel $channel, Event $event): void
    {
        abort_unless($channel->events()->whereKey($event->id)->exists(), 404);
    }

    private function requireState(Channel $channel, Event $event, string $state, string $message): void
    {
        abort_unless($this->stateOf($channel, $event) === $state, 422, $message);
    }

    private function matchesCategories(Channel $channel, Event $event): bool
    {
        return $event->categories()->whereIn('categories.id', $channel->categories()->pluck('categories.id'))->exists();
    }

    private function setState(Channel $channel, Event $event, ?string $type, User $by): void
    {
        if ($type === null) {
            $event->channelPicks()->detach($channel->id);

            return;
        }

        $event->channelPicks()->syncWithoutDetaching([$channel->id => ['relationship_type' => $type, 'added_by_user_id' => $by->id]]);
    }
}
