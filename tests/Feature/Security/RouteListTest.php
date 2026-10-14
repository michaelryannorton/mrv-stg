<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every page carries a list of route addresses for the front end (Ziggy). A signed-out visitor must
 * not receive the /admin and /curate addresses; see config/ziggy.php and resources/views/app.blade.php.
 */
class RouteListTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> the route names listed in the page's Ziggy script */
    private function routeNamesIn(string $html): array
    {
        preg_match_all('/"([A-Za-z0-9._-]+)":\{"uri":/', $html, $matches);

        return $matches[1];
    }

    public function test_a_signed_out_visitor_gets_only_the_sign_in_routes_when_the_gate_is_on(): void
    {
        config(['app.invite_only' => true]);

        $names = $this->routeNamesIn($this->get('/login')->assertOk()->getContent());

        $this->assertEqualsCanonicalizing(config('ziggy.groups.sign_in'), $names);
    }

    public function test_a_signed_out_visitor_gets_the_public_routes_too_when_the_gate_is_off(): void
    {
        config(['app.invite_only' => false]);

        $names = $this->routeNamesIn($this->get('/events')->assertOk()->getContent());

        $this->assertEqualsCanonicalizing(
            array_merge(config('ziggy.groups.sign_in'), config('ziggy.groups.public')),
            $names,
        );
        $this->assertEmpty(array_filter($names, fn ($n) => str_starts_with($n, 'admin.') || str_starts_with($n, 'curate.')));
    }

    public function test_no_signed_out_page_lists_an_admin_or_curator_address(): void
    {
        foreach ([true, false] as $gate) {
            config(['app.invite_only' => $gate]);

            foreach (['/login', '/forgot-password', '/invitation/sometoken', '/reset-password/sometoken'] as $path) {
                $html = $this->get($path)->assertOk()->getContent();

                $this->assertStringNotContainsString('admin\/', $html);
                $this->assertStringNotContainsString('curate', $html);
                $this->assertStringNotContainsString('dashboard', $html);
            }
        }
    }

    public function test_a_signed_in_user_gets_every_route(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('administrator'));

        $names = $this->routeNamesIn($this->get('/dashboard')->assertOk()->getContent());

        $this->assertContains('admin.events.index', $names);
        $this->assertContains('curate.index', $names);
        $this->assertContains('dashboard', $names);
        $this->assertGreaterThan(50, count($names));
    }

    public function test_every_name_in_the_groups_is_a_real_route(): void
    {
        $groups = config('ziggy.groups');
        $this->assertSame(['sign_in', 'public'], array_keys($groups));

        foreach ($groups as $group => $names) {
            foreach ($names as $name) {
                $this->assertTrue(Route::has($name), "ziggy group {$group} names a route that does not exist: {$name}");
            }
        }
    }

    public function test_login_and_logout_ask_the_browser_for_a_full_page_load(): void
    {
        $user = User::factory()->create();

        $login = $this->post('/login', ['email' => $user->email, 'password' => 'password'], ['X-Inertia' => 'true']);
        $login->assertStatus(409);
        $login->assertHeader('X-Inertia-Location', route('dashboard'));

        $logout = $this->actingAs($user)->post('/logout', [], ['X-Inertia' => 'true']);
        $logout->assertStatus(409);
        $logout->assertHeader('X-Inertia-Location', url('/'));
    }

    public function test_login_and_logout_redirect_normally_for_a_plain_request(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->post('/logout')->assertRedirect('/');
    }
}
