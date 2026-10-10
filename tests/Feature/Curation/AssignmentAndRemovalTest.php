<?php

namespace Tests\Feature\Curation;

use App\Models\CuratorAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/** Phase 2 slice 1c-ii: assigning channels, removing accounts, and where people land after login. */
class AssignmentAndRemovalTest extends CurationTestCase
{
    public function test_an_administrator_assigns_a_channel_and_the_curator_gains_access(): void
    {
        $channel = $this->channel('Music');
        $user = $this->curator();
        $this->actingAs($this->admin());

        $this->post(route('admin.users.assignments.store', $user), ['channel_id' => $channel->id, 'role' => 'lead'])
            ->assertRedirect()->assertSessionHas('success');

        $assignment = CuratorAssignment::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('lead', $assignment->role);
        $this->assertSame('active', $assignment->status);
        $this->assertNotNull($assignment->started_at);

        $this->actingAs($user)->get(route('curate.channels.show', $channel->slug))->assertOk();
    }

    public function test_assigning_validates_the_role_the_channel_and_the_person(): void
    {
        $music = $this->channel('Music');
        $archived = $this->channel('Old', status: 'archived');
        $curator = $this->curator();
        $this->actingAs($this->admin());

        $this->post(route('admin.users.assignments.store', $curator), ['channel_id' => $music->id, 'role' => 'boss'])->assertSessionHasErrors('role');
        $this->post(route('admin.users.assignments.store', $curator), ['channel_id' => 999, 'role' => 'curator'])->assertSessionHasErrors('channel_id');
        $this->post(route('admin.users.assignments.store', $curator), ['channel_id' => $archived->id, 'role' => 'curator'])->assertSessionHasErrors('channel_id');
        $this->post(route('admin.users.assignments.store', User::factory()->create()), ['channel_id' => $music->id, 'role' => 'curator'])->assertStatus(422);
        $this->post(route('admin.users.assignments.store', $this->admin()), ['channel_id' => $music->id, 'role' => 'curator'])->assertNotFound();

        $this->assertSame(0, CuratorAssignment::count());
    }

    public function test_assigning_again_updates_the_role_and_ending_then_reassigning_reactivates_the_same_row(): void
    {
        $channel = $this->channel('Music');
        $user = $this->curator();
        $this->actingAs($this->admin());

        $this->post(route('admin.users.assignments.store', $user), ['channel_id' => $channel->id, 'role' => 'curator']);
        $this->post(route('admin.users.assignments.store', $user), ['channel_id' => $channel->id, 'role' => 'lead']);
        $this->assertSame(1, CuratorAssignment::count());
        $this->assertSame('lead', CuratorAssignment::first()->role);

        $id = CuratorAssignment::first()->id;
        $this->delete(route('admin.users.assignments.destroy', [$user, $channel]))->assertRedirect();
        $ended = CuratorAssignment::first();
        $this->assertSame('ended', $ended->status);
        $this->assertNotNull($ended->ended_at);

        $this->post(route('admin.users.assignments.store', $user), ['channel_id' => $channel->id, 'role' => 'contributor']);
        $again = CuratorAssignment::first();
        $this->assertSame($id, $again->id);
        $this->assertSame('active', $again->status);
        $this->assertNull($again->ended_at);
        $this->assertSame('contributor', $again->role);
    }

    public function test_the_assignment_role_can_be_changed_and_only_an_active_assignment_can_be_changed_or_ended(): void
    {
        $channel = $this->channel('Music');
        $user = $this->curator('curator', $channel);
        $this->actingAs($this->admin());

        $this->put(route('admin.users.assignments.update', [$user, $channel]), ['role' => 'lead'])->assertRedirect();
        $this->assertSame('lead', CuratorAssignment::first()->role);
        $this->put(route('admin.users.assignments.update', [$user, $channel]), ['role' => 'boss'])->assertSessionHasErrors('role');

        $this->delete(route('admin.users.assignments.destroy', [$user, $channel]));
        $this->put(route('admin.users.assignments.update', [$user, $channel]), ['role' => 'curator'])->assertNotFound();
        $this->delete(route('admin.users.assignments.destroy', [$user, $channel]))->assertNotFound();
    }

    public function test_ending_an_assignment_takes_access_away_immediately(): void
    {
        $channel = $this->channel('Music');
        $user = $this->curator('curator', $channel);
        $admin = $this->admin();

        $this->actingAs($admin)->delete(route('admin.users.assignments.destroy', [$user, $channel]));

        $this->actingAs($user)->get(route('curate.channels.show', $channel->slug))->assertNotFound();
    }

    public function test_only_administrators_can_manage_assignments(): void
    {
        $channel = $this->channel('Music');
        $target = $this->curator();

        foreach (['editor', 'curator'] as $role) {
            $this->actingAs($this->curator($role, $channel));

            $this->post(route('admin.users.assignments.store', $target), ['channel_id' => $channel->id, 'role' => 'lead'])->assertForbidden();
            $this->put(route('admin.users.assignments.update', [$target, $channel]), ['role' => 'lead'])->assertForbidden();
            $this->delete(route('admin.users.assignments.destroy', [$target, $channel]))->assertForbidden();
        }

        $this->assertSame(2, CuratorAssignment::count()); // only the two helper users' own assignments
    }

    public function test_the_manage_page_and_the_users_list_show_assignments(): void
    {
        $music = $this->channel('Music');
        $this->channel('Food');
        $user = $this->curator('curator', $music);
        $this->actingAs($this->admin());

        $this->get(route('admin.users.edit', $user))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/edit')
                ->has('assignments', 1)
                ->where('assignments.0.channel_name', 'Music')
                ->where('assignments.0.status', 'active')
                ->has('available_channels', 1)
                ->where('available_channels.0.name', 'Food')
                ->where('assignment_roles', ['lead', 'curator', 'contributor']));

        $this->get(route('admin.users.index'))
            ->assertInertia(fn (Assert $page) => $page->where('users', fn ($users) => collect($users)->firstWhere('id', $user->id)['channels'] === ['Music']));
    }

    public function test_removing_an_accepted_account_deletes_it_logs_it_out_and_keeps_its_picks(): void
    {
        $music = $this->category('Local Music');
        $channel = $this->channel('Music', [$music]);
        $user = $this->curator('curator', $channel);
        $event = $this->event('Concert', [$music]);
        $this->actingAs($user);
        $this->act($channel, $event, 'feature');
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);

        $this->actingAs($this->admin())->post(route('admin.users.remove', $user))->assertRedirect(route('admin.users.index'))->assertSessionHas('success');

        $this->assertNull(User::find($user->id));
        $this->assertSame(0, CuratorAssignment::count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('model_has_roles')->where('model_id', $user->id)->count());
        $this->assertSame('featured', $this->state($channel, $event));
        $this->assertNull(DB::table('event_channels')->where('event_id', $event->id)->value('added_by_user_id'));
    }

    public function test_remove_is_refused_for_pending_and_administrator_accounts_and_for_non_administrators(): void
    {
        $pending = User::factory()->create(['invited_at' => now()])->assignRole('curator');
        $otherAdmin = $this->admin();
        $curator = $this->curator();
        $this->actingAs($this->admin());

        $this->post(route('admin.users.remove', $pending))->assertStatus(422);
        $this->post(route('admin.users.remove', $otherAdmin))->assertNotFound();

        $this->actingAs($this->curator());
        $this->post(route('admin.users.remove', $curator))->assertForbidden();

        $this->assertNotNull(User::find($pending->id));
        $this->assertNotNull(User::find($otherAdmin->id));
        $this->assertNotNull(User::find($curator->id));
    }

    public function test_after_login_curators_land_in_the_workspace_and_everyone_else_on_the_dashboard(): void
    {
        $password = 'a-long-test-password-1';
        $cases = [
            'curator' => route('curate.index', absolute: false),
            'editor' => route('curate.index', absolute: false),
            'administrator' => route('dashboard', absolute: false),
            'none' => route('dashboard', absolute: false),
        ];

        foreach ($cases as $role => $expected) {
            $user = User::factory()->create(['password' => $password]);
            if ($role !== 'none') {
                $user->assignRole($role);
            }

            $this->post(route('login'), ['email' => $user->email, 'password' => $password])->assertRedirect($expected);
            auth()->logout();
        }
    }

    public function test_the_sidebar_props_let_curators_see_the_curate_link_but_not_the_admin_links(): void
    {
        $this->actingAs($this->curator());
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.permissions', fn ($p) => in_array('channels.curate', collect($p)->all(), true))
            ->where('auth.roles', ['curator']));
    }
}
