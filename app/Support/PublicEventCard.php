<?php

namespace App\Support;

use App\Models\Event;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

/**
 * The one definition of what a public page may receive about an event. Every public page that
 * lists events (/events, the channel pages) builds its payload here from an explicit field list, so
 * a column added to the events table later is never sent to the browser by accident. Internal and
 * moderation columns (overridden_fields, stale_fields, editorial_status, access_scope,
 * verification_status, created_by_user_id, the full description, coordinates) are deliberately
 * absent; adding a field here is a public-disclosure decision.
 */
class PublicEventCard
{
    /** Relations the card reads, with only the columns it needs. */
    public const RELATIONS = [
        'venue:id,name,city',
        'organizer:id,name',
        'categories:id,name,slug',
        'tags:id,name,slug',
        'audiences:id,name,slug',
    ];

    /** @param bool|null $isPick null leaves the key out (pages that have no notion of picks). */
    public static function make(Event $event, ?bool $isPick = null): array
    {
        $taxonomy = fn (Collection $items) => $items
            ->map(fn ($item) => ['id' => $item->id, 'name' => $item->name, 'slug' => $item->slug])
            ->values();

        $card = [
            'id' => $event->id,
            'uuid' => $event->uuid,
            'title' => $event->title,
            'slug' => $event->slug,
            'short_description' => $event->short_description,
            'start_at' => $event->start_at,
            'end_at' => $event->end_at,
            'timezone' => $event->timezone,
            'all_day' => $event->all_day,
            'is_free' => $event->is_free,
            'details_url' => $event->details_url,
            'venue' => $event->venue ? ['id' => $event->venue->id, 'name' => $event->venue->name, 'city' => $event->venue->city] : null,
            'organizer' => $event->organizer ? ['id' => $event->organizer->id, 'name' => $event->organizer->name] : null,
            'categories' => $taxonomy($event->categories),
            'tags' => $taxonomy($event->tags),
            'audiences' => $taxonomy($event->audiences),
        ];

        if ($isPick !== null) {
            $card['is_pick'] = $isPick;
        }

        return $card;
    }

    /** @param iterable<Event> $events */
    public static function collection(iterable $events): BaseCollection
    {
        return collect($events)->map(fn (Event $event) => self::make($event))->values();
    }
}
