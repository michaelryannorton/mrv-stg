<?php

namespace Tests\Feature\Moderation;

use App\Models\Category;
use App\Models\Channel;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The review queue's approve, reject and revert actions, which decide what the public site shows.
 * These run against the test database only; the real backlog is never touched by the test suite.
 */
class EventModerationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrator');
    }

    private function event(string $title, array $attributes = []): Event
    {
        return Event::create([
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::random(4),
            'start_at' => now()->addDays(3),
            'timezone' => 'America/Los_Angeles',
            'status' => 'candidate',
            'editorial_status' => 'pending_review',
            ...$attributes,
        ]);
    }

    private function bulk(string $action, array $events)
    {
        return $this->post(route('admin.events.bulk'), ['action' => $action, 'ids' => collect($events)->pluck('id')->all()]);
    }

    public function test_approving_publishes_a_pending_event_and_makes_it_publicly_visible(): void
    {
        $event = $this->event('Concert');
        $this->actingAs($this->admin());
        $this->assertFalse(Event::published()->whereKey($event->id)->exists());

        $this->bulk('approve', [$event])->assertRedirect()->assertSessionHas('success', '1 event approved and published.');

        $event->refresh();
        $this->assertSame('published', $event->editorial_status);
        $this->assertSame('scheduled', $event->status);
        $this->assertNotNull($event->published_at);
        $this->assertTrue(Event::published()->whereKey($event->id)->exists());
    }

    public function test_approving_keeps_an_existing_status_and_published_time(): void
    {
        $when = now()->subDays(10)->startOfSecond();
        $event = $this->event('Cancelled show', ['status' => 'cancelled', 'published_at' => $when]);
        $this->actingAs($this->admin());

        $this->bulk('approve', [$event]);

        $event->refresh();
        $this->assertSame('cancelled', $event->status);
        $this->assertTrue($event->published_at->equalTo($when));
    }

    public function test_rejecting_hides_the_event_and_reverting_sends_it_back_to_review(): void
    {
        $event = $this->event('Spam event', ['status' => 'scheduled', 'editorial_status' => 'published']);
        $this->actingAs($this->admin());

        $this->bulk('reject', [$event])->assertSessionHas('success', '1 event rejected.');
        $this->assertSame('rejected', $event->fresh()->editorial_status);
        $this->assertFalse(Event::published()->whereKey($event->id)->exists());

        $this->bulk('revert', [$event])->assertSessionHas('success', '1 event moved back to pending review.');
        $this->assertSame('pending_review', $event->fresh()->editorial_status);
    }

    public function test_a_bulk_action_applies_to_every_selected_event_and_only_those(): void
    {
        [$a, $b, $c] = [$this->event('A'), $this->event('B'), $this->event('C')];
        $this->actingAs($this->admin());

        $this->bulk('approve', [$a, $b])->assertSessionHas('success', '2 events approved and published.');

        $this->assertSame('published', $a->fresh()->editorial_status);
        $this->assertSame('published', $b->fresh()->editorial_status);
        $this->assertSame('pending_review', $c->fresh()->editorial_status);
    }

    public function test_bulk_validates_the_action_the_ids_and_that_events_exist(): void
    {
        $event = $this->event('A');
        $this->actingAs($this->admin());

        $this->post(route('admin.events.bulk'), ['action' => 'delete', 'ids' => [$event->id]])->assertSessionHasErrors('action');
        $this->post(route('admin.events.bulk'), ['action' => 'approve', 'ids' => []])->assertSessionHasErrors('ids');
        $this->post(route('admin.events.bulk'), ['action' => 'approve'])->assertSessionHasErrors('ids');
        $this->post(route('admin.events.bulk'), ['action' => 'approve', 'ids' => [999999]])->assertSessionHasErrors('ids.0');

        $this->assertSame('pending_review', $event->fresh()->editorial_status);
    }

    public function test_the_queue_tabs_and_counts_follow_the_editorial_status(): void
    {
        $this->event('Pending one');
        $this->event('Unreviewed one', ['editorial_status' => 'unreviewed']);
        $this->event('Published one', ['status' => 'scheduled', 'editorial_status' => 'published']);
        $this->event('Rejected one', ['editorial_status' => 'rejected']);
        $this->actingAs($this->admin());

        $this->get(route('admin.events.index'))->assertInertia(fn (Assert $page) => $page
            ->where('status', 'pending')
            ->has('events', 2)
            ->where('counts', ['pending' => 2, 'published' => 1, 'rejected' => 1]));
        $this->get(route('admin.events.index', ['status' => 'published']))->assertInertia(fn (Assert $page) => $page->has('events', 1)->where('events.0.title', 'Published one'));
        $this->get(route('admin.events.index', ['status' => 'rejected']))->assertInertia(fn (Assert $page) => $page->has('events', 1)->where('events.0.title', 'Rejected one'));
    }

    public function test_the_queue_exposes_the_access_scope_the_confirmation_dialog_depends_on(): void
    {
        $this->event('Gated', ['access_scope' => 'public_with_eligibility']);
        $this->event('Open', ['access_scope' => 'public']);
        $this->actingAs($this->admin());

        $this->get(route('admin.events.index'))->assertInertia(fn (Assert $page) => $page
            ->where('events', fn ($events) => collect($events)->pluck('access_scope', 'title')->all() === ['Gated' => 'public_with_eligibility', 'Open' => 'public']));
    }

    public function test_approval_moves_an_event_from_a_channel_curators_view_of_pending_to_published(): void
    {
        $category = Category::create(['name' => 'Local Music', 'slug' => 'local-music']);
        $channel = Channel::create(['name' => 'Music', 'slug' => 'music', 'status' => 'active']);
        $channel->categories()->attach($category->id);
        $event = $this->event('Concert');
        $event->categories()->attach($category->id);
        $this->actingAs($this->admin());

        $this->assertSame(1, $channel->events()->count());
        $this->assertSame(0, $channel->events()->published()->count());

        $this->bulk('approve', [$event]);

        $this->assertSame(1, $channel->events()->published()->count());
        $this->get(route('admin.channels.index'))->assertInertia(fn (Assert $page) => $page->where('channels.0.published_events_count', 1));
    }

    public function test_editors_and_curators_cannot_approve_reject_or_revert(): void
    {
        $event = $this->event('Concert');

        foreach (['editor', 'curator'] as $role) {
            $this->actingAs(User::factory()->create()->assignRole($role));
            foreach (['approve', 'reject', 'revert'] as $action) {
                $this->bulk($action, [$event])->assertForbidden();
            }
        }

        $this->assertSame('pending_review', $event->fresh()->editorial_status);
        $this->assertSame('candidate', $event->fresh()->status);
    }
}
