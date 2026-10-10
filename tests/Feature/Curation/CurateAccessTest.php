<?php

namespace Tests\Feature\Curation;

use App\Models\CuratorAssignment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/** Phase 2 slice 1c-ii: who can reach the curator workspace and which channels. */
class CurateAccessTest extends CurationTestCase
{
    public function test_guests_are_redirected_to_login(): void
    {
        $channel = $this->channel('Music');

        $this->get(route('curate.index'))->assertRedirect(route('login'));
        $this->get(route('curate.channels.show', $channel->slug))->assertRedirect(route('login'));
    }

    public function test_a_user_without_the_curate_permission_gets_403_everywhere_in_the_workspace(): void
    {
        $channel = $this->channel('Music');
        $event = $this->event('Concert');

        foreach ([User::factory()->create(), User::factory()->create()->assignRole('organization_manager')] as $user) {
            $this->actingAs($user);

            $this->get(route('curate.index'))->assertForbidden();
            $this->get(route('curate.channels.show', $channel->slug))->assertForbidden();
            $this->act($channel, $event, 'feature')->assertForbidden();
        }
    }

    public function test_the_index_lists_only_the_channels_the_user_is_assigned_to(): void
    {
        $mine = $this->channel('Music');
        $this->channel('Food');
        $user = $this->curator('curator', $mine, 'lead');
        $this->actingAs($user);

        $this->get(route('curate.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('curate/index')
                ->has('channels', 1)
                ->where('channels.0.slug', 'music')
                ->where('channels.0.assignment_role', 'lead'));
    }

    public function test_an_unassigned_or_nonexistent_channel_gives_404_not_403(): void
    {
        $this->channel('Music');
        $other = $this->channel('Food');
        $event = $this->event('Tasting');
        $this->actingAs($this->curator('curator', $this->channel('Art')));

        $this->get(route('curate.channels.show', $other->slug))->assertNotFound();
        $this->get(route('curate.channels.show', 'no-such-channel'))->assertNotFound();
        $this->act($other, $event, 'feature')->assertNotFound();
        $this->act($other, $event, 'add')->assertNotFound();
    }

    public function test_an_ended_assignment_loses_access(): void
    {
        $channel = $this->channel('Music');
        $user = $this->curator('curator', $channel);
        $this->actingAs($user)->get(route('curate.channels.show', $channel->slug))->assertOk();

        CuratorAssignment::where('user_id', $user->id)->update(['status' => 'ended', 'ended_at' => now()]);

        $this->get(route('curate.channels.show', $channel->slug))->assertNotFound();
        $this->get(route('curate.index'))->assertInertia(fn (Assert $page) => $page->has('channels', 0));
    }

    public function test_an_archived_channel_is_closed_to_curators_but_not_to_an_administrator(): void
    {
        $channel = $this->channel('Old', status: 'archived');
        $user = $this->curator('curator', $channel);

        $this->actingAs($user)->get(route('curate.channels.show', $channel->slug))->assertNotFound();
        $this->get(route('curate.index'))->assertInertia(fn (Assert $page) => $page->has('channels', 0));

        $this->actingAs($this->admin())->get(route('curate.channels.show', $channel->slug))->assertOk();
    }

    public function test_an_editor_works_like_a_curator(): void
    {
        $channel = $this->channel('Music');
        $other = $this->channel('Food');
        $this->actingAs($this->curator('editor', $channel));

        $this->get(route('curate.channels.show', $channel->slug))->assertOk();
        $this->get(route('curate.channels.show', $other->slug))->assertNotFound();
    }

    public function test_an_administrator_reaches_every_active_channel_without_an_assignment(): void
    {
        $music = $this->channel('Music');
        $this->channel('Food', status: 'draft');
        $this->actingAs($this->admin());

        $this->get(route('curate.channels.show', $music->slug))->assertOk();
        $this->get(route('curate.index'))->assertInertia(fn (Assert $page) => $page->has('channels', 2));
    }

    public function test_a_curator_holding_an_assignment_without_the_permission_is_still_blocked(): void
    {
        // The assignment is scope, not capability: the global role must also grant channels.curate.
        $channel = $this->channel('Music');
        $user = User::factory()->create();
        CuratorAssignment::create(['channel_id' => $channel->id, 'user_id' => $user->id, 'status' => 'active', 'started_at' => now()]);

        $this->actingAs($user)->get(route('curate.channels.show', $channel->slug))->assertForbidden();
    }

    public function test_a_curator_cannot_approve_edit_or_reach_any_admin_page(): void
    {
        $channel = $this->channel('Music');
        $event = $this->event('Concert', [], ['editorial_status' => 'pending_review']);
        $this->actingAs($this->curator('curator', $channel));

        $this->post(route('admin.events.bulk'), ['action' => 'approve', 'ids' => [$event->id]])->assertForbidden();
        $this->put(route('admin.events.update', $event), ['title' => 'Changed'])->assertForbidden();
        $this->get(route('admin.events.index'))->assertForbidden();
        $this->assertSame('Concert', $event->fresh()->title);
    }
}
