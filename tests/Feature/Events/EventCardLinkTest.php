<?php

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventSource;
use App\Models\Source;
use App\Support\PublicEventCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Which link a public card carries (Michael, 2026-11-27): the event's own address, otherwise the web
 * page of the feed it came from; never the feed address and never a link out of the description.
 */
class EventCardLinkTest extends TestCase
{
    use RefreshDatabase;

    private function event(?string $own = null, string $description = ''): Event
    {
        return Event::create([
            'title' => 'Linked', 'slug' => 'linked-'.Str::random(6), 'start_at' => now()->addDay(), 'timezone' => 'America/Los_Angeles',
            'canonical_url' => $own, 'description' => $description,
        ]);
    }

    private function attachFeed(Event $event, ?string $webPage, string $collector = 'ics', bool $primary = true): Source
    {
        $source = Source::create([
            'name' => 'Feed '.Str::random(4), 'source_type' => 'ics', 'collector_type' => $collector, 'active' => true,
            'base_url' => $webPage, 'feed_url' => 'https://calendar.example/private-TOKEN/basic.ics',
        ]);
        EventSource::create([
            'event_id' => $event->id, 'source_id' => $source->id, 'external_id' => Str::random(8), 'source_url' => $source->feed_url,
            'first_seen_at' => now(), 'last_seen_at' => now(), 'is_primary' => $primary,
        ]);

        return $source;
    }

    private function link(Event $event): ?array
    {
        return PublicEventCard::link($event->fresh()->load(PublicEventCard::RELATIONS));
    }

    public function test_the_events_own_address_comes_first(): void
    {
        $event = $this->event('https://example.org/this-event');
        $this->attachFeed($event, 'https://example.org/calendar');

        $this->assertSame(['url' => 'https://example.org/this-event', 'kind' => 'event'], $this->link($event));
    }

    public function test_without_one_the_feeds_web_page_is_used(): void
    {
        $event = $this->event(null, '<a href="https://example.org/a-form">Form</a>');
        $this->attachFeed($event, 'https://startupmojave.example/calendar');

        $this->assertSame(['url' => 'https://startupmojave.example/calendar', 'kind' => 'source'], $this->link($event));
    }

    public function test_a_link_in_the_description_and_the_feed_address_are_never_used(): void
    {
        $event = $this->event(null, 'Details: https://example.org/a-document');
        $this->attachFeed($event, null);

        $this->assertNull($this->link($event));
    }

    public function test_the_primary_feed_wins_and_unsafe_or_manual_pages_are_skipped(): void
    {
        $event = $this->event();
        $this->attachFeed($event, 'https://second.example', primary: false);
        $this->attachFeed($event, 'https://first.example');
        $this->assertSame('https://first.example', $this->link($event)['url']);

        $other = $this->event();
        $this->attachFeed($other, 'javascript:alert(1)');
        $this->attachFeed($other, 'https://directory.example', 'manual', false);
        $this->assertNull($this->link($other));
    }

    public function test_the_public_list_never_contains_the_feed_address(): void
    {
        $event = $this->event();
        $event->update(['editorial_status' => 'published', 'status' => 'published']);
        $this->attachFeed($event, 'https://example.org/calendar');

        $response = $this->get(route('events.index'));

        $this->assertStringNotContainsString('private-TOKEN', json_encode($response->viewData('page')));
        $this->assertStringNotContainsString('base_url', json_encode($response->viewData('page')));
    }
}
