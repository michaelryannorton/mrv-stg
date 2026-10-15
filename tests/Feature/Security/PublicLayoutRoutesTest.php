<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The public layout's header and footer call route() for every page they link. A signed-out visitor only
 * receives the route names in config/ziggy.php, so a name the layout uses that is missing there throws in
 * the browser and blanks the page. These tests read the names out of the front end source, so a link added
 * later cannot slip through (see components/mrv/navigation.ts).
 */
class PublicLayoutRoutesTest extends TestCase
{
    private function source(string $path): string
    {
        return file_get_contents(base_path($path));
    }

    /** @return list<string> */
    private function routeNamesCalledByTheShell(): array
    {
        $names = [];

        foreach (['resources/js/components/mrv/site-header.tsx', 'resources/js/components/mrv/site-footer.tsx', 'resources/js/layouts/public-layout.tsx'] as $file) {
            preg_match_all("/route\('([A-Za-z0-9._-]+)'/", $this->source($file), $m);
            $names = array_merge($names, $m[1]);
        }

        // Pages the navigation links with a route name (the preview navigation uses literal addresses).
        preg_match_all("/route: '([A-Za-z0-9._-]+)'/", $this->source('resources/js/components/mrv/navigation.ts'), $m);

        return array_values(array_unique(array_merge($names, $m[1])));
    }

    public function test_the_shell_calls_the_routes_it_is_expected_to(): void
    {
        $names = $this->routeNamesCalledByTheShell();

        foreach (['home', 'login', 'dashboard', 'events.index', 'channels.index', 'submit.create'] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    public function test_every_route_the_shell_calls_exists(): void
    {
        foreach ($this->routeNamesCalledByTheShell() as $name) {
            $this->assertTrue(Route::has($name), "The public layout calls route('{$name}'), which does not exist.");
        }
    }

    public function test_every_route_the_shell_calls_reaches_a_signed_out_visitor(): void
    {
        $reachable = array_merge(config('ziggy.groups.sign_in'), config('ziggy.groups.public'));

        // `dashboard` is only called for a signed-in user, who receives every route.
        foreach (array_diff($this->routeNamesCalledByTheShell(), ['dashboard']) as $name) {
            $this->assertContains($name, $reachable, "route('{$name}') is called by the public layout but is not in the sign_in or public group of config/ziggy.php.");
        }
    }

    public function test_the_component_sheet_exists_only_in_the_local_environment(): void
    {
        // Registered in routes/web.php only when app()->environment('local'); the suite runs as 'testing'.
        $this->assertSame('testing', app()->environment());
        $this->assertFalse(Route::has('port.components'));
    }
}
