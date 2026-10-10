<?php

namespace Tests\Feature\Curation;

use App\Models\CuratorProfile;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/** Phase 2 slice 1c-iii: a curator edits only their own public profile. */
class CuratorProfileTest extends CurationTestCase
{
    private function save(array $data = [])
    {
        return $this->put(route('curate.profile.update'), ['display_name' => 'Casey C.', 'blurb' => 'Hello.', 'is_public' => true, ...$data]);
    }

    public function test_guests_are_redirected_and_users_without_profile_edit_get_403(): void
    {
        $this->get(route('curate.profile.edit'))->assertRedirect(route('login'));
        $this->put(route('curate.profile.update'), ['display_name' => 'X'])->assertRedirect(route('login'));

        foreach ([User::factory()->create(), User::factory()->create()->assignRole('organization_manager')] as $user) {
            $this->actingAs($user);
            $this->get(route('curate.profile.edit'))->assertForbidden();
            $this->save()->assertForbidden();
        }
        $this->assertSame(0, CuratorProfile::count());
    }

    public function test_the_page_defaults_to_the_users_name_and_writes_nothing_on_view(): void
    {
        $user = User::factory()->create(['name' => 'Casey Curator'])->assignRole('curator');

        $this->actingAs($user)->get(route('curate.profile.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('curate/profile')
                ->where('profile.display_name', 'Casey Curator')
                ->where('profile.blurb', '')
                ->where('profile.is_public', false)
                ->where('blurb_limit', 300));

        $this->assertSame(0, CuratorProfile::count());
    }

    public function test_saving_creates_the_profile_once_and_later_saves_update_the_same_row(): void
    {
        $user = User::factory()->create()->assignRole('curator');
        $this->actingAs($user);

        $this->save()->assertRedirect()->assertSessionHas('success');
        $this->save(['display_name' => 'Casey Curator', 'blurb' => 'Second version.', 'is_public' => false]);

        $this->assertSame(1, CuratorProfile::count());
        $profile = $user->fresh()->curatorProfile;
        $this->assertSame('Casey Curator', $profile->display_name);
        $this->assertSame('Second version.', $profile->blurb);
        $this->assertFalse($profile->is_public);
    }

    public function test_the_edit_page_shows_the_saved_values(): void
    {
        $user = User::factory()->create()->assignRole('editor');
        $this->actingAs($user);
        $this->save(['display_name' => 'Ed', 'blurb' => 'Short bio.', 'is_public' => true]);

        $this->get(route('curate.profile.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('profile.display_name', 'Ed')
            ->where('profile.blurb', 'Short bio.')
            ->where('profile.is_public', true));
    }

    public function test_the_blurb_is_limited_to_300_characters_not_bytes(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('curator'));

        $this->save(['blurb' => str_repeat('a', 300)])->assertSessionHasNoErrors();
        $this->save(['blurb' => str_repeat('a', 301)])->assertSessionHasErrors('blurb');
        $this->save(['blurb' => str_repeat('é', 300)])->assertSessionHasNoErrors(); // 600 bytes
        $this->save(['blurb' => str_repeat('é', 301)])->assertSessionHasErrors('blurb');
    }

    public function test_browser_line_breaks_count_as_one_character_and_the_blurb_is_trimmed(): void
    {
        $user = User::factory()->create()->assignRole('curator');
        $this->actingAs($user);

        // 298 characters, a CRLF break, one more character: 300 once the break is one character.
        $this->save(['blurb' => str_repeat('a', 298)."\r\n".'b'])->assertSessionHasNoErrors();
        $this->assertSame(str_repeat('a', 298)."\n".'b', $user->fresh()->curatorProfile->blurb);

        $this->save(['blurb' => "   padded   \n"]);
        $this->assertSame('padded', $user->fresh()->curatorProfile->blurb);
    }

    public function test_an_empty_blurb_is_stored_as_null_and_the_name_is_required(): void
    {
        $user = User::factory()->create()->assignRole('curator');
        $this->actingAs($user);

        $this->save(['blurb' => ''])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->curatorProfile->blurb);

        $this->save(['display_name' => ''])->assertSessionHasErrors('display_name');
        $this->save(['display_name' => str_repeat('n', 101)])->assertSessionHasErrors('display_name');
    }

    public function test_a_profile_is_private_unless_the_flag_is_sent(): void
    {
        $user = User::factory()->create()->assignRole('curator');
        $this->actingAs($user);

        $this->put(route('curate.profile.update'), ['display_name' => 'Casey'])->assertSessionHasNoErrors();

        $this->assertFalse($user->fresh()->curatorProfile->is_public);
    }

    public function test_a_user_can_never_change_someone_elses_profile(): void
    {
        $other = User::factory()->create()->assignRole('curator');
        $other->curatorProfile()->create(['display_name' => 'Other Person', 'blurb' => 'Original.', 'is_public' => true]);
        $mine = User::factory()->create()->assignRole('curator');
        $this->actingAs($mine);

        // Extra fields that name another user or profile are ignored: there is no id to aim at.
        $this->save(['display_name' => 'Hijack', 'user_id' => $other->id, 'id' => $other->curatorProfile->id]);

        $other->refresh();
        $this->assertSame('Other Person', $other->curatorProfile->display_name);
        $this->assertSame('Original.', $other->curatorProfile->blurb);
        $this->assertSame($mine->id, CuratorProfile::where('display_name', 'Hijack')->value('user_id'));
        $this->assertSame(2, CuratorProfile::count());
    }

    public function test_the_page_never_shows_another_users_profile(): void
    {
        User::factory()->create()->assignRole('curator')->curatorProfile()->create(['display_name' => 'Other Person', 'blurb' => 'Secret.', 'is_public' => true]);
        $mine = User::factory()->create(['name' => 'Me Myself'])->assignRole('curator');

        $this->actingAs($mine)->get(route('curate.profile.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('profile.display_name', 'Me Myself')->where('profile.blurb', ''));
    }

    public function test_an_administrator_has_a_profile_too(): void
    {
        $this->actingAs($this->admin());

        $this->save()->assertSessionHasNoErrors();
        $this->assertSame(1, CuratorProfile::count());
    }

    public function test_removing_an_account_removes_its_profile(): void
    {
        $user = User::factory()->create()->assignRole('curator');
        $user->curatorProfile()->create(['display_name' => 'Casey', 'is_public' => true]);

        $this->actingAs($this->admin())->post(route('admin.users.remove', $user))->assertRedirect();

        $this->assertSame(0, CuratorProfile::count());
    }
}
