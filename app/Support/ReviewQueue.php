<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Source;
use Illuminate\Support\Collection;

/**
 * Turns the events of one review queue tab into rows: the dates of one feed's events with the same
 * title are one row, so a repeating event is read once and decided once. Events that did not come
 * from a feed (clipped, submitted by the public) are never grouped, since two with the same title
 * may be unrelated. Administrator pages only; nothing here is public.
 */
class ReviewQueue
{
    public const RELATIONS = [
        'venue:id,name,city,latitude,longitude,venue_type',
        'audiences:id,name',
        'organizer:id,name',
        'categories:id,name,slug',
        'tags:id,name,slug',
        'eventSources:id,event_id,source_id,is_primary',
        'eventSources.source:id,name,base_url,collector_type',
    ];

    /** @var array<string, ?string> cleaned descriptions by their stored text; the dates of a series mostly repeat it */
    private array $cleaned = [];

    /**
     * @param  Collection<int, Event>  $events  already in the order the rows should come out
     * @param  array<string, true>  $approvedKeys  keys of series that have a published event (see key())
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $events, array $approvedKeys = []): array
    {
        return $events
            ->groupBy(fn (Event $event) => self::key($event))
            ->map(fn (Collection $group, string $key) => $this->row($key, $group->values(), isset($approvedKeys[$key])))
            ->values()
            ->all();
    }

    /** What makes two events dates of one series: the same feed and the same title. */
    public static function key(Event $event): string
    {
        $source = self::source($event);

        if ($source === null || ! $source->isFeed()) {
            return 'event-'.$event->id;
        }

        return self::seriesKey($source->id, $event->title);
    }

    public static function seriesKey(int $sourceId, string $title): string
    {
        return 'feed-'.$sourceId.'-'.mb_strtolower(trim(preg_replace('/\s+/u', ' ', $title)));
    }

    private static function source(Event $event): ?Source
    {
        return $event->eventSources->sortBy([['is_primary', 'desc'], ['id', 'asc']])->first()?->source;
    }

    /** @param Collection<int, Event> $events */
    private function row(string $key, Collection $events, bool $approvedBefore): array
    {
        $first = $events->first();
        $descriptions = [];
        $dates = [];

        foreach ($events as $event) {
            $text = $this->describe($event->description);
            $index = array_search($text, $descriptions, true);

            if ($index === false) {
                $descriptions[] = $text;
                $index = count($descriptions) - 1;
            }

            $dates[] = [
                'id' => $event->id,
                'start_at' => $event->start_at,
                'timezone' => $event->timezone,
                'all_day' => $event->all_day,
                'place' => PublicEventCard::place($event),
                'city' => $event->venue?->city,
                'online' => $event->venue?->venue_type === 'online',
                'mapped' => ($event->latitude ?? $event->venue?->latitude) !== null,
                'is_free' => $event->is_free,
                'description' => $index,
                'access_scope' => $event->access_scope,
                'link' => PublicEventCard::link($event),
                'original_url' => $event->details_url,
                'stale' => $event->hasStaleFields(),
            ];
        }

        $taxonomy = fn (string $relation) => $events
            ->flatMap(fn (Event $event) => $event->{$relation})
            ->unique('id')
            ->sortBy('name')
            ->map(fn ($item) => ['name' => $item->name, 'slug' => $item->slug])
            ->values();

        return [
            'key' => $key,
            'title' => $first->title,
            'source' => self::source($first)?->name,
            'organizer' => $first->organizer?->name,
            'categories' => $taxonomy('categories'),
            'audiences' => $events->flatMap(fn (Event $event) => $event->audiences)->pluck('name')->unique()->sort()->values(),
            'tags' => $taxonomy('tags'),
            'descriptions' => $descriptions,
            'places_differ' => collect($dates)->pluck('place')->unique()->count() > 1,
            'descriptions_differ' => count($descriptions) > 1,
            'approved_before' => $approvedBefore,
            'dates' => $dates,
        ];
    }

    private function describe(?string $stored): ?string
    {
        $stored = (string) $stored;

        if (! array_key_exists($stored, $this->cleaned)) {
            $this->cleaned[$stored] = PlainText::clean($stored);
        }

        return $this->cleaned[$stored];
    }
}
