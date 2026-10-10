<?php

namespace Tests\Feature\Invitations;

use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/** Phase 2 slice 1c-i: invitations, acceptance, resend, revoke and role changes. */
class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['name' => 'Owner'])->assignRole('administrator');
    }

    /** Sends an invitation as the administrator and returns the invited user and the emailed token. */
    private function invite(string $email = 'curator@example.test', string $role = 'curator', string $name = 'Casey Curator'): array
    {
        Notification::fake();

        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), ['name' => $name, 'email' => $email, 'role' => $role])
            ->assertRedirect(route('admin.users.index'));

        $user = User::where('email', strtolower($email))->firstOrFail();
        $token = null;
        Notification::assertSentTo($user, UserInvitation::class, function (UserInvitation $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        auth()->logout();

        return [$user, $token];
    }

    private function accept(string $token, string $email, string $password = 'a-long-new-password-1'): TestResponse
    {
        return $this->post(route('invitation.store'), [
            'token' => $token,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    public function test_an_administrator_invites_a_user_who_starts_pending_with_the_role(): void
    {
        [$user] = $this->invite('Casey@Example.Test');

        $this->assertSame('casey@example.test', $user->email);
        $this->assertTrue($user->hasRole('curator'));
        $this->assertTrue($user->isPending());
        $this->assertNotNull($user->invited_at);
        $this->assertNotNull($user->invitedBy);
        $this->assertSame('Owner', $user->invitedBy->name);
        $this->assertNull($user->invitation_accepted_at);
        Notification::assertSentToTimes($user, UserInvitation::class, 1);
    }

    public function test_the_emailed_link_points_at_the_invitation_page_with_the_email(): void
    {
        [$user, $token] = $this->invite();

        $mail = (new UserInvitation($token, 'Owner', 'curator'))->toMail($user);

        $this->assertStringContainsString("/invitation/{$token}?email=".urlencode($user->email), $mail->actionUrl);
        $this->assertStringContainsString('Owner', implode(' ', $mail->introLines));
    }

    public function test_the_administrator_role_cannot_be_invited_and_unknown_roles_are_rejected(): void
    {
        Notification::fake();
        $this->actingAs($this->admin());

        foreach (['administrator', 'organization_manager', 'nonsense'] as $role) {
            $this->post(route('admin.users.store'), ['name' => 'X', 'email' => 'x@example.test', 'role' => $role])
                ->assertSessionHasErrors('role');
        }

        $this->assertSame(1, User::count());
        Notification::assertNothingSent();
    }

    public function test_an_existing_email_is_rejected_with_a_message_that_depends_on_its_state(): void
    {
        $this->actingAs($this->admin());
        User::factory()->create(['email' => 'active@example.test']);
        $pending = User::factory()->create(['email' => 'pending@example.test', 'invited_at' => now()]);

        $this->post(route('admin.users.store'), ['name' => 'X', 'email' => 'Active@example.test', 'role' => 'curator'])
            ->assertSessionHasErrors(['email' => 'An account with this email already exists.']);
        $this->post(route('admin.users.store'), ['name' => 'X', 'email' => $pending->email, 'role' => 'curator'])
            ->assertSessionHasErrors(['email' => 'This person already has a pending invitation. Use Resend on the Users page.']);
    }

    public function test_a_failed_send_keeps_no_account_and_no_token(): void
    {
        $this->actingAs($this->admin());
        Notification::swap(new class extends NotificationFake
        {
            public function send($notifiables, $notification): void
            {
                throw new RuntimeException('Postmark refused the recipient');
            }

            public function sendNow($notifiables, $notification, ?array $channels = null): void
            {
                throw new RuntimeException('Postmark refused the recipient');
            }
        });

        $this->from(route('admin.users.create'))
            ->post(route('admin.users.store'), ['name' => 'Casey', 'email' => 'casey@example.test', 'role' => 'curator'])
            ->assertRedirect(route('admin.users.create'))
            ->assertSessionHas('error')
            ->assertSessionHasInput('email', 'casey@example.test');

        $this->assertNull(User::where('email', 'casey@example.test')->first());
        $this->assertSame(0, DB::table('invitation_tokens')->count());
        $this->assertStringContainsString('Postmark refused the recipient', session('error'));
    }

    public function test_the_invitation_page_greets_the_invitee_when_the_link_is_valid(): void
    {
        [$user, $token] = $this->invite();

        $this->get(route('invitation.show', ['token' => $token, 'email' => $user->email]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/accept-invitation')
                ->where('valid', true)
                ->where('name', 'Casey Curator')
                ->where('email', $user->email));
    }

    public function test_the_invitation_page_reveals_nothing_for_unknown_wrong_or_missing_links(): void
    {
        [$user] = $this->invite();

        foreach ([
            ['wrong-token', $user->email],
            ['wrong-token', 'nobody@example.test'],
            ['wrong-token', ''],
        ] as [$token, $email]) {
            $this->get(route('invitation.show', ['token' => $token, 'email' => $email]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('valid', false)->where('name', '')->where('email', ''));
        }
    }

    public function test_accepting_sets_the_password_once_and_the_user_can_log_in(): void
    {
        [$user, $token] = $this->invite();

        $this->accept($token, $user->email)->assertRedirect(route('login'))->assertSessionHas('status');

        $user->refresh();
        $this->assertFalse($user->isPending());
        $this->assertNotNull($user->invitation_accepted_at);
        $this->assertTrue(Hash::check('a-long-new-password-1', $user->password));
        $this->assertSame(0, DB::table('invitation_tokens')->count());

        $this->post(route('login'), ['email' => $user->email, 'password' => 'a-long-new-password-1']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_same_link_is_rejected_after_use(): void
    {
        [$user, $token] = $this->invite();
        $this->accept($token, $user->email);

        $this->accept($token, $user->email, 'another-long-password-2')
            ->assertSessionHasErrors(['email' => 'This invitation link is no longer valid. Ask the administrator to send a new one.']);

        $this->assertTrue(Hash::check('a-long-new-password-1', $user->fresh()->password));
    }

    public function test_the_link_expires_after_seven_days(): void
    {
        [$user, $token] = $this->invite();

        $this->travel(8)->days();
        $this->accept($token, $user->email)
            ->assertSessionHasErrors(['email' => 'This invitation link is no longer valid. Ask the administrator to send a new one.']);
        $this->assertTrue($user->fresh()->isPending());
    }

    public function test_the_link_still_works_on_day_six(): void
    {
        [$user, $token] = $this->invite();

        $this->travel(6)->days();
        $this->accept($token, $user->email)->assertRedirect(route('login'));
    }

    public function test_a_wrong_token_and_an_unknown_email_get_the_same_error(): void
    {
        [$user, $token] = $this->invite();
        $expected = 'This invitation link is no longer valid. Ask the administrator to send a new one.';

        $this->accept('wrong-token', $user->email)->assertSessionHasErrors(['email' => $expected]);
        $this->accept($token, 'nobody@example.test')->assertSessionHasErrors(['email' => $expected]);
    }

    public function test_the_accepted_email_is_matched_case_insensitively(): void
    {
        [$user, $token] = $this->invite();

        $this->accept($token, strtoupper($user->email))->assertRedirect(route('login'));
    }

    public function test_a_weak_or_mismatched_password_is_rejected_and_the_link_stays_usable(): void
    {
        [$user, $token] = $this->invite();

        $this->post(route('invitation.store'), ['token' => $token, 'email' => $user->email, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
        $this->post(route('invitation.store'), ['token' => $token, 'email' => $user->email, 'password' => 'a-long-new-password-1', 'password_confirmation' => 'different'])
            ->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->isPending());
        $this->accept($token, $user->email)->assertRedirect(route('login'));
    }

    public function test_resending_replaces_the_token_and_is_throttled(): void
    {
        [$user, $firstToken] = $this->invite();
        $admin = $this->admin();
        $this->actingAs($admin);

        // Too soon after the first email.
        $this->post(route('admin.users.resend', $user))->assertSessionHas('error');

        $this->travel(2)->minutes();
        Notification::fake();
        $this->post(route('admin.users.resend', $user))->assertSessionHas('success');

        $secondToken = null;
        Notification::assertSentTo($user, UserInvitation::class, function (UserInvitation $n) use (&$secondToken) {
            $secondToken = $n->token;

            return true;
        });

        auth()->logout();
        $this->assertNotSame($firstToken, $secondToken);
        $this->accept($firstToken, $user->email)->assertSessionHasErrors('email');
        $this->accept($secondToken, $user->email)->assertRedirect(route('login'));
    }

    public function test_only_a_pending_invitation_can_be_resent_or_revoked(): void
    {
        $active = User::factory()->create();
        $this->actingAs($this->admin());
        Notification::fake();

        $this->post(route('admin.users.resend', $active))->assertStatus(422);
        $this->delete(route('admin.users.destroy', $active))->assertStatus(422);

        $this->assertNotNull($active->fresh());
        Notification::assertNothingSent();
    }

    public function test_revoking_a_pending_invitation_removes_the_account_and_the_token(): void
    {
        [$user, $token] = $this->invite();
        $this->actingAs($this->admin());

        $this->delete(route('admin.users.destroy', $user))->assertRedirect(route('admin.users.index'));

        $this->assertNull(User::find($user->id));
        $this->assertSame(0, DB::table('invitation_tokens')->count());
        auth()->logout();
        $this->accept($token, $user->email)->assertSessionHasErrors('email');
    }

    public function test_the_role_can_be_changed_between_editor_and_curator_only(): void
    {
        $user = User::factory()->create()->assignRole('curator');
        $this->actingAs($this->admin());

        $this->put(route('admin.users.update', $user), ['role' => 'editor'])->assertRedirect(route('admin.users.index'));
        $this->assertSame(['editor'], $user->fresh()->getRoleNames()->all());

        $this->put(route('admin.users.update', $user), ['role' => 'administrator'])->assertSessionHasErrors('role');
        $this->assertSame(['editor'], $user->fresh()->getRoleNames()->all());
    }

    public function test_administrator_accounts_cannot_be_managed_from_the_users_pages(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create()->assignRole('administrator');
        $this->actingAs($admin);

        $this->get(route('admin.users.edit', $other))->assertNotFound();
        $this->put(route('admin.users.update', $other), ['role' => 'curator'])->assertNotFound();
        $this->assertTrue($other->fresh()->hasRole('administrator'));
    }

    public function test_the_users_page_lists_status_and_role(): void
    {
        $this->actingAs($this->admin());
        User::factory()->create(['name' => 'Pat', 'invited_at' => now()])->assignRole('curator');

        $this->get(route('admin.users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/index')
                ->has('users', 2)
                ->where('users.0.name', 'Owner')
                ->where('users.0.editable', false)
                ->where('users.1.name', 'Pat')
                ->where('users.1.status', 'invited')
                ->where('users.1.roles', ['curator']));
    }

    public function test_only_administrators_can_invite_resend_or_revoke(): void
    {
        $target = User::factory()->create(['invited_at' => now()]);
        Notification::fake();

        foreach (['editor', 'curator'] as $role) {
            $this->actingAs(User::factory()->create()->assignRole($role));

            $this->post(route('admin.users.store'), ['name' => 'X', 'email' => 'x@example.test', 'role' => 'curator'])->assertForbidden();
            $this->post(route('admin.users.resend', $target))->assertForbidden();
            $this->delete(route('admin.users.destroy', $target))->assertForbidden();
        }

        $this->assertNotNull($target->fresh());
        Notification::assertNothingSent();
        $this->assertNull(User::where('email', 'x@example.test')->first());
    }

    public function test_finishing_a_normal_password_reset_also_completes_a_pending_invitation(): void
    {
        [$user] = $this->invite();
        $token = Password::broker('users')->createToken($user);

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-long-new-password-1',
            'password_confirmation' => 'a-long-new-password-1',
        ])->assertRedirect(route('login'));

        $this->assertFalse($user->fresh()->isPending());
    }

    public function test_an_account_that_was_never_invited_is_not_pending(): void
    {
        $this->assertFalse(User::factory()->create()->isPending());
    }

    public function test_shared_props_carry_the_signed_in_users_roles_and_permissions(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('curator'));
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.roles', ['curator'])
            ->where('auth.permissions', fn ($permissions) => collect($permissions)->sort()->values()->all() === ['channels.curate', 'profile.edit']));

        $this->actingAs($this->admin());
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.roles', ['administrator'])
            ->where('auth.permissions', fn ($permissions) => in_array('users.manage', collect($permissions)->all(), true)));
    }
}
