<?php

namespace Tests\Feature\Authorization;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every /admin route is administrator-only (Phase 2 slice 1a). The tests walk the live route list,
 * so a new admin.* route added without the role middleware fails here instead of shipping open.
 */
class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<LaravelRoute> */
    private function adminRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (LaravelRoute $route) => str_starts_with($route->getName() ?? '', 'admin.'))
            ->values()
            ->all();
    }

    private function request(LaravelRoute $route, Event $event)
    {
        $parameters = collect($route->parameterNames())->mapWithKeys(fn ($name) => [$name => $event->id])->all();

        return $this->call($route->methods()[0], route($route->getName(), $parameters));
    }

    private function event(): Event
    {
        return Event::create([
            'title' => 'Access test event',
            'slug' => 'access-test-event',
            'start_at' => now()->addDay(),
            'timezone' => 'America/Los_Angeles',
        ]);
    }

    public function test_there_are_admin_routes_to_protect(): void
    {
        $this->assertGreaterThanOrEqual(11, count($this->adminRoutes()));
    }

    public function test_guests_are_redirected_to_login_on_every_admin_route(): void
    {
        $event = $this->event();

        foreach ($this->adminRoutes() as $route) {
            $response = $this->request($route, $event);

            $this->assertTrue(
                $response->isRedirect() && str_contains($response->headers->get('Location'), '/login'),
                "Guest was not redirected to login on {$route->getName()} (status {$response->status()})."
            );
        }
    }

    public function test_a_user_with_no_role_gets_403_on_every_admin_route(): void
    {
        $event = $this->event();
        $this->actingAs(User::factory()->create());

        foreach ($this->adminRoutes() as $route) {
            $this->assertSame(403, $this->request($route, $event)->status(), "Role-less user was not blocked on {$route->getName()}.");
        }
    }

    public function test_editors_and_curators_get_403_on_every_admin_route(): void
    {
        $event = $this->event();

        foreach (['editor', 'curator', 'organization_manager'] as $role) {
            $this->actingAs(User::factory()->create()->assignRole($role));

            foreach ($this->adminRoutes() as $route) {
                $this->assertSame(403, $this->request($route, $event)->status(), "A {$role} was not blocked on {$route->getName()}.");
            }
        }
    }

    public function test_the_role_check_runs_before_model_binding(): void
    {
        $this->actingAs(User::factory()->create());

        // No event with this id exists. A 404 here would mean binding ran first and leaked which ids exist.
        $this->get(route('admin.events.edit', 999999))->assertForbidden();
    }

    public function test_an_administrator_is_not_blocked_on_any_admin_route(): void
    {
        $event = $this->event();
        $this->actingAs(User::factory()->create()->assignRole('administrator'));

        foreach ($this->adminRoutes() as $route) {
            $response = $this->request($route, $event);

            $this->assertNotSame(403, $response->status(), "Administrator was blocked on {$route->getName()}.");
            $this->assertFalse(
                $response->isRedirect() && str_contains($response->headers->get('Location'), '/login'),
                "Administrator was sent to login on {$route->getName()}."
            );
        }
    }

    public function test_the_dashboard_stays_open_to_any_logged_in_user(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))->assertOk();
    }
}
