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
 * verification_status, created_by_user_id, coordinates) are deliberately absent; adding a field
 * here is a public-disclosure decision. The place text, the description (as plain text) and the
 * eligibility note were added by Michael's decision of 2026-11-27.
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
        'eventSources:id,event_id,source_id,is_primary',
        'eventSources.source:id,base_url,collector_type',
    ];

    /** Access scopes for which a visitor is told to check that they may attend. */
    private const ELIGIBILITY_SCOPES = ['mixed', 'public_with_eligibility'];

    /**
     * @param  bool|null  $isPick  null leaves the key out (pages that have no notion of picks).
     * @param  bool  $withText  false for the week and month grids, which show no descriptions.
     */
    public static function make(Event $event, ?bool $isPick = null, bool $withText = true): array
    {
        $taxonomy = fn (Collection $items) => $items
            ->map(fn ($item) => ['id' => $item->id, 'name' => $item->name, 'slug' => $item->slug])
            ->values();

        $description = $withText ? PlainText::clean($event->description) : null;
        $summary = $withText ? PlainText::clean($event->short_description) : null;

        $card = [
            'id' => $event->id,
            'uuid' => $event->uuid,
            'title' => $event->title,
            'slug' => $event->slug,
            'start_at' => $event->start_at,
            'end_at' => $event->end_at,
            'timezone' => $event->timezone,
            'all_day' => $event->all_day,
            'is_free' => $event->is_free,
            'place' => self::place($event),
            'excerpt' => PlainText::excerpt($summary ?? $description),
            'description' => $description,
            'check_eligibility' => in_array($event->access_scope, self::ELIGIBILITY_SCOPES, true),
            'link' => self::link($event),
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
    public static function collection(iterable $events, bool $withText = true): BaseCollection
    {
        return collect($events)->map(fn (Event $event) => self::make($event, null, $withText))->values();
    }

    /** The venue and its city, otherwise the feed's own location text. */
    public static function place(Event $event): ?string
    {
        if ($event->venue) {
            return $event->venue->name.($event->venue->city ? ', '.$event->venue->city : '');
        }

        return PlainText::line($event->location_name_override);
    }

    /**
     * Where the card sends a visitor: the event's own address, otherwise the web page of the feed
     * it came from. Links inside a description are never used: they may be forms or attachments.
     *
     * @return array{url: string, kind: 'event'|'source'}|null
     */
    public static function link(Event $event): ?array
    {
        if ($event->details_url) {
            return ['url' => $event->details_url, 'kind' => 'event'];
        }

        $page = $event->eventSources
            ->sortBy([['is_primary', 'desc'], ['id', 'asc']])
            ->map(fn ($link) => $link->source?->isFeed() ? $link->source->webPage() : null)
            ->first(fn (?string $url) => $url !== null);

        return $page ? ['url' => $page, 'kind' => 'source'] : null;
    }
}
