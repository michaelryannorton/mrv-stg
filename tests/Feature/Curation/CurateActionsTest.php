<?php

namespace Tests\Feature\Curation;

use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/** Phase 2 slice 1c-ii: the curator's picks and the state each action leaves behind. */
class CurateActionsTest extends CurationTestCase
{
    private function setUpChannel(): array
    {
        $music = $this->category('Local Music');
        $food = $this->category('Food');
        $channel = $this->channel('Music', [$music]);
        $user = $this->curator('curator', $channel);
        $this->actingAs($user);

        return [$channel, $user, $music, $food];
    }

    public function test_feature_marks_a_category_member_featured_and_records_who_did_it(): void
    {
        [$channel, $user, $music] = $this->setUpChannel();
        $event = $this->event('Concert', [$music]);

        $this->act($channel, $event, 'feature')->assertRedirect()->assertSessionHas('success');

        $this->assertSame('featured', $this->state($channel, $event));
        $this->assertSame($user->id, DB::table('event_channels')->where('event_id', $event->id)->value('added_by_user_id'));
        $this->assertTrue($channel->events()->whereKey($event->id)->exists());
    }

    public function test_featuring_twice_keeps_one_row(): void
    {
        [$channel, , $music] = $this->setUpChannel();
        $event = $this->event('Concert', [$music]);

        $this->act($channel, $event, 'feature');
        $this->act($channel, $event, 'feature')->assertRedirect();

        $this->assertSame(1, DB::table('event_channels')->where('event_id', $event->id)->count());
    }

    public function test_an_event_outside_the_channel_cannot_be_featured_or_excluded(): void
    {
        [$channel, , , $food] = $this->setUpChannel();
        $event = $this->event('Tasting', [$food]);

        $this->act($channel, $event, 'feature')->assertNotFound();
        $this->act($channel, $event, 'exclude')->assertNotFound();
        $this->assertNull($this->state($channel, $event));
    }

    public function test_unfeature_returns_a_category_member_to_automatic_membership(): void
    {
        [$channel, , $music] = $this->setUpChannel();
        $event = $this->event('Concert', [$music]);
        $this->act($channel, $event, 'feature');

        $this->act($channel, $event, 'unfeature')->assertRedirect();

        $this->assertNull($this->state($channel, $event));
        $this->assertTrue($channel->events()->whereKey($event->id)->exists());
    }

    public function test_unfeature_keeps_a_hand_added_event_as_curated(): void
    {
        [$channel, , , $food] = $this->setUpChannel();
        $event = $this->event('Tasting', [$food]);
        $this->act($channel, $event, 'add');
        $this->act($channel, $event, 'feature');
        $this->assertSame('featured', $this->state($channel, $event));

        $this->act($channel, $event, 'unfeature');

        $this->assertSame('curated', $this->state($channel, $event));
        $this->assertTrue($channel->events()->whereKey($event->id)->exists());
    }

    public function test_unfeature_of_an_event_that_is_not_featured_is_refused(): void
    {
        [$channel, , $music] = $this->setUpChannel();
        $event = $this->event('Concert', [$music]);

        $this->act($channel, $event, 'unfeature')->assertNotFound();
    }

    public function test_exclude_removes_the_event_from_the_channel_and_restore_brings_it_back(): void
    {
        [$channel, , $music] = $this->setUpChannel();
        $event = $this->event('Concert', [$music]);
        $this->act($channel, $event, 'feature');

        $this->act($channel, $event, 'exclude')->assertRedirect();
        $this->assertSame('excluded', $this->state($channel, $event));
        $this->assertFalse($channel->events()->whereKey($event->id)->exists());

        $this->act($channel, $event, 'restore')->assertRedirect();
        $this->assertNull($this->state($channel, $event));
        $this->assertTrue($channel->events()->whereKey($event->id)->exists());
    }

    public function test_restore_of_an_event_that_is_not_excluded_is_refused(): void
    {
        [$channel, , $music] = $this->setUpChannel();
        $event = $this->event('Concert', [$music]);

        $this->act($channel, $event, 'restore')->assertNotFound();
    }

    public function test_add_puts_an_outside_event_in_the_channel_as_curated(): void
    {
        [$channel, , , $food] = $this->setUpChannel();
        $event = $this->event('Tasting', [$food]);

        $this->act($channel, $event, 'add')->assertRedirect()->assertSessionHas('success');

        $this->assertSame('curated', $this->state($channel, $event));
        $this->assertTrue($channel->events()->whereKey($event->id)->exists());
    }

    public function test_add_is_refused_for_an_event_already_in_the_channel_or_already_excluded(): void
    {
        [$channel, , $music, $food] = $this->setUpChannel();
        $member = $this->event('Concert', [$music]);
        $excluded = $this->event('Tasting', [$food]);
        $this->act($channel, $excluded, 'add');
        $this->act($channel, $excluded, 'exclude');

        $this->act($channel, $member, 'add')->assertStatus(422);
        $this->act($channel, $excluded, 'add')->assertStatus(422);
        $this->assertNull($this->state($channel, $member));
        $this->assertSame('excluded', $this->state($channel, $excluded));
    }

    public function test_events_a_curator_must_not_see_cannot_be_acted_on(): void
    {
        [$channel, , $music] = $this->setUpChannel();
        $rejected = $this->event('Rejected', [$music], ['editorial_status' => 'rejected']);
        $draft = $this->event('Draft', [$music], ['status' => 'draft', 'editorial_status' => 'unreviewed']);
        $past = $this->event('Past', [], ['start_at' => now()->subDays(5), 'end_at' => now()->subDays(5)->addHour()]);

        foreach (['feature', 'exclude'] as $action) {
            $this->act($channel, $rejected, $action)->assertNotFound();
            $this->act($channel, $draft, $action)->assertNotFound();
        }
        $this->act($channel, $rejected, 'add')->assertNotFound();
        $this->act($channel, $past, 'add')->assertNotFound();
        $this->assertSame(0, DB::table('event_channels')->count());
    }

    public function test_a_pending_event_can_be_featured_but_stays_unpublished(): void
    {
        [$channel, , $music] = $this->setUpChannel();
        $pending = $this->event('Pending', [$music], ['status' => 'candidate', 'editorial_status' => 'pending_review']);

        $this->act($channel, $pending, 'feature')->assertRedirect();

        $pending->refresh();
        $this->assertSame('featured', $this->state($channel, $pending));
        $this->assertSame('pending_review', $pending->editorial_status);
        $this->assertSame('candidate', $pending->status);
        $this->assertFalse(Event::published()->whereKey($pending->id)->exists());
        $this->assertFalse($channel->events()->published()->whereKey($pending->id)->exists());
    }

    public function test_an_unassigned_curator_changes_nothing(): void
    {
        [, , $music] = $this->setUpChannel();
        $other = $this->channel('Food', [$music]);
        $event = $this->event('Concert', [$music]);

        foreach (['feature', 'exclude', 'add', 'unfeature', 'restore'] as $action) {
            $this->act($other, $event, $action)->assertNotFound();
        }
        $this->assertSame(0, DB::table('event_channels')->count());
    }

    public function test_the_workspace_lists_upcoming_members_in_date_order_with_their_state(): void
    {
        [$channel, , $music] = $this->setUpChannel();
        $this->event('Later', [$music], ['start_at' => now()->addDays(9)]);
        $soon = $this->event('Soon', [$music], ['start_at' => now()->addDay()]);
        $this->event('Gone', [$music], ['start_at' => now()->subDays(3), 'end_at' => now()->subDays(3)->addHour()]);
        $this->event('Elsewhere', []);
        $this->act($channel, $soon, 'feature');

        $this->get(route('curate.channels.show', $channel->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('curate/show')
                ->has('events.data', 2)
                ->where('events.data.0.title', 'Soon')
                ->where('events.data.0.state', 'featured')
                ->where('events.data.1.title', 'Later')
                ->where('events.data.1.state', 'category')
                ->where('events.data.1.is_public', true)
                ->where('tab', 'events')
                ->where('excluded_count', 0));
    }

    public function test_the_excluded_tab_lists_excluded_events(): void
    {
        [$channel, , $music] = $this->setUpChannel();
        $event = $this->event('Concert', [$music]);
        $this->act($channel, $event, 'exclude');

        $this->get(route('curate.channels.show', [$channel->slug, 'tab' => 'excluded']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'excluded')
                ->has('events.data', 1)
                ->where('events.data.0.state', 'excluded')
                ->where('excluded_count', 1));
        $this->get(route('curate.channels.show', $channel->slug))->assertInertia(fn (Assert $page) => $page->has('events.data', 0));
    }

    public function test_search_finds_upcoming_outside_events_by_title_and_skips_members_and_picked_events(): void
    {
        [$channel, , $music, $food] = $this->setUpChannel();
        $this->event('Veterans Breakfast', [$food]);
        $this->event('Veterans Concert', [$music]);
        $excluded = $this->event('Veterans Parade', [$food]);
        $this->act($channel, $excluded, 'add');
        $this->act($channel, $excluded, 'exclude');
        $this->event('Veterans Past', [], ['start_at' => now()->subDay(), 'end_at' => now()->subDay()->addHour()]);
        $this->event('Garden Show', [$food]);

        $this->get(route('curate.channels.show', [$channel->slug, 'find' => 'veterans']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('find', 'veterans')
                ->has('results', 1)
                ->where('results.0.title', 'Veterans Breakfast'));
    }

    public function test_search_needs_two_characters_and_treats_wildcards_as_plain_text(): void
    {
        [$channel, , , $food] = $this->setUpChannel();
        $this->event('Garden Show', [$food]);
        $this->event('100% Local Market', [$food]);

        $this->get(route('curate.channels.show', [$channel->slug, 'find' => 'g']))->assertInertia(fn (Assert $page) => $page->has('results', 0));
        $this->get(route('curate.channels.show', [$channel->slug, 'find' => '%%']))->assertInertia(fn (Assert $page) => $page->has('results', 0));
        $this->get(route('curate.channels.show', [$channel->slug, 'find' => '100%']))
            ->assertInertia(fn (Assert $page) => $page->has('results', 1)->where('results.0.title', '100% Local Market'));
        $this->get(route('curate.channels.show', [$channel->slug, 'find' => '_arden']))->assertInertia(fn (Assert $page) => $page->has('results', 0));
    }

    public function test_an_administrator_can_curate_any_channel(): void
    {
        $music = $this->category('Local Music');
        $channel = $this->channel('Music', [$music]);
        $event = $this->event('Concert', [$music]);
        $this->actingAs($this->admin());

        $this->act($channel, $event, 'feature')->assertRedirect();
        $this->assertSame('featured', $this->state($channel, $event));
    }
}
