<?php

namespace Tests\Feature\Channels;

use App\Models\Category;
use App\Models\Channel;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Event::scopeInChannel() is the single definition of channel membership (Phase 2 slice 1b):
 * linked categories plus curated/featured rows, minus excluded rows.
 */
class ChannelMembershipTest extends TestCase
{
    use RefreshDatabase;

    private function category(string $name): Category
    {
        return Category::create(['name' => $name, 'slug' => Str::slug($name)]);
    }

    private function channel(string $name, array $categories = []): Channel
    {
        $channel = Channel::create(['name' => $name, 'slug' => Str::slug($name), 'status' => 'active']);
        $channel->categories()->sync(collect($categories)->pluck('id')->all());

        return $channel;
    }

    private function event(string $title, array $categories = [], array $attributes = []): Event
    {
        $event = Event::create([
            'title' => $title,
            'slug' => Str::slug($title),
            'start_at' => now()->addDay(),
            'timezone' => 'America/Los_Angeles',
            ...$attributes,
        ]);
        $event->categories()->sync(collect($categories)->pluck('id')->all());

        return $event;
    }

    private function pick(Event $event, Channel $channel, string $type): void
    {
        $event->channelPicks()->attach($channel->id, ['relationship_type' => $type, 'added_by_user_id' => User::factory()->create()->id]);
    }

    private function titles(Channel $channel): array
    {
        return $channel->events()->orderBy('title')->pluck('title')->all();
    }

    public function test_events_in_a_linked_category_are_in_the_channel_and_others_are_not(): void
    {
        $music = $this->category('Local Music');
        $food = $this->category('Food');
        $channel = $this->channel('Music', [$music]);
        $this->event('Concert', [$music]);
        $this->event('Tasting', [$food]);
        $this->event('Uncategorized');

        $this->assertSame(['Concert'], $this->titles($channel));
    }

    public function test_an_event_in_two_linked_categories_is_counted_once(): void
    {
        $music = $this->category('Local Music');
        $nightlife = $this->category('Nightlife');
        $channel = $this->channel('After dark', [$music, $nightlife]);
        $this->event('Late show', [$music, $nightlife]);

        $this->assertSame(['Late show'], $this->titles($channel));
        $this->assertSame(1, $channel->events()->count());
    }

    public function test_an_excluded_row_removes_an_event_its_category_would_include(): void
    {
        $music = $this->category('Local Music');
        $channel = $this->channel('Music', [$music]);
        $kept = $this->event('Kept', [$music]);
        $removed = $this->event('Removed', [$music]);
        $this->pick($removed, $channel, 'excluded');

        $this->assertSame(['Kept'], $this->titles($channel));
        $this->assertNotNull($kept->id);
    }

    public function test_curated_and_featured_rows_add_events_the_categories_do_not_include(): void
    {
        $music = $this->category('Local Music');
        $food = $this->category('Food');
        $channel = $this->channel('Music', [$music]);
        $curated = $this->event('Hand picked', [$food]);
        $featured = $this->event('Highlight');
        $this->event('Left out', [$food]);
        $this->pick($curated, $channel, 'curated');
        $this->pick($featured, $channel, 'featured');

        $this->assertSame(['Hand picked', 'Highlight'], $this->titles($channel));
    }

    public function test_a_channel_with_no_linked_category_contains_only_curated_and_featured_events(): void
    {
        $music = $this->category('Local Music');
        $channel = $this->channel('Hand curated');
        $picked = $this->event('Picked', [$music]);
        $this->event('Ignored', [$music]);

        $this->assertSame([], $this->titles($channel));

        $this->pick($picked, $channel, 'featured');

        $this->assertSame(['Picked'], $this->titles($channel));
    }

    public function test_rows_for_one_channel_do_not_affect_another(): void
    {
        $music = $this->category('Local Music');
        $first = $this->channel('First', [$music]);
        $second = $this->channel('Second', [$music]);
        $event = $this->event('Shared', [$music]);
        $this->pick($event, $first, 'excluded');

        $this->assertSame([], $this->titles($first));
        $this->assertSame(['Shared'], $this->titles($second));
    }

    public function test_membership_includes_pending_events_and_published_scope_narrows_it(): void
    {
        $music = $this->category('Local Music');
        $channel = $this->channel('Music', [$music]);
        $this->event('Live', [$music], ['status' => 'scheduled', 'editorial_status' => 'published']);
        $this->event('Waiting', [$music], ['status' => 'candidate', 'editorial_status' => 'pending_review']);

        $this->assertSame(2, $channel->events()->count());
        $this->assertSame(['Live'], $channel->events()->published()->pluck('title')->all());
    }
}
