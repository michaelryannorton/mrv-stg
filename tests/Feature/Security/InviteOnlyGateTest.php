<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Staging shows nothing to signed-out visitors (STAGING_INVITE_ONLY): every address goes to the
 * login page except the pages that signing in needs. With the setting off nothing changes.
 */
class InviteOnlyGateTest extends TestCase
{
    use RefreshDatabase;

    private function gateOn(): void
    {
        config(['app.invite_only' => true]);
    }

    /** @return array<string, array{0: string}> */
    public static function closedAddresses(): array
    {
        return [
            'front page' => ['/'],
            'events' => ['/events'],
            'channels' => ['/channels'],
            'a channel page' => ['/channels/anything'],
            'submit an event' => ['/submit-event'],
            'dashboard' => ['/dashboard'],
            'admin' => ['/admin/events'],
            'curator workspace' => ['/curate'],
            'settings' => ['/settings/profile'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function openAddresses(): array
    {
        return [
            'login' => ['/login'],
            'forgot password' => ['/forgot-password'],
            'reset password link' => ['/reset-password/sometoken'],
            'invitation link' => ['/invitation/sometoken'],
        ];
    }

    #[DataProvider('closedAddresses')]
    public function test_a_signed_out_visitor_is_sent_to_login_for_every_other_address(string $path): void
    {
        $this->gateOn();

        $response = $this->get($path);

        $response->assertRedirect(route('login'));
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    #[DataProvider('openAddresses')]
    public function test_the_sign_in_pages_stay_open_to_a_signed_out_visitor(string $path): void
    {
        $this->gateOn();

        $response = $this->get($path);

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_a_signed_out_post_to_a_closed_address_is_sent_to_login(): void
    {
        $this->gateOn();

        $this->post('/submit-event', ['title' => 'x'])->assertRedirect(route('login'));
        $this->post('/logout')->assertRedirect(route('login'));
    }

    public function test_the_sign_in_posts_are_not_blocked(): void
    {
        $this->gateOn();
        $user = User::factory()->create();

        // A wrong password is a validation error on the form, not a redirect away from it.
        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');

        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nobody@example.com'])
            ->assertSessionHasNoErrors();

        $this->from('/invitation/sometoken')->post('/invitation', ['token' => 'bad', 'email' => 'x@example.com'])
            ->assertRedirect('/invitation/sometoken');
    }

    public function test_the_health_check_stays_reachable(): void
    {
        $this->gateOn();

        $this->get('/up')->assertOk();
    }

    public function test_a_signed_in_user_passes_and_is_still_marked_noindex(): void
    {
        $this->gateOn();

        $response = $this->actingAs(User::factory()->create())->get('/dashboard');

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_an_address_that_matches_no_route_is_a_plain_404_for_everyone(): void
    {
        $this->gateOn();

        // Laravel answers these before any web middleware runs, so the gate does not see them. A 404
        // shows a visitor nothing, and registration must stay a 404 (RegistrationTest), so no catch-all
        // route is added to route them through the gate.
        $this->get('/wp-login.php')->assertNotFound();
        $this->actingAs(User::factory()->create())->get('/wp-login.php')->assertNotFound();
    }

    public function test_with_the_setting_off_public_pages_are_open_and_no_header_is_added(): void
    {
        config(['app.invite_only' => false]);

        foreach (['/', '/events', '/channels', '/submit-event'] as $path) {
            $response = $this->get($path);
            $response->assertOk();
            $response->assertHeaderMissing('X-Robots-Tag');
        }

        $this->get('/wp-login.php')->assertNotFound();
    }

    public function test_the_setting_is_off_by_default(): void
    {
        $this->assertFalse(config('app.invite_only'));
    }
}
