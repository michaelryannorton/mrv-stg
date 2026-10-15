<?php

namespace Tests\Feature\PublicPages;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The public pages ported from the production weather site (port phase 3): routes, the invite-only gate, the
 * local-only preview switch, the Ziggy route lists, and the integrity of the Route 66 data and photos they serve.
 */
class PortedPagesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string, 2: string}> path, route name, Inertia component */
    public static function pages(): array
    {
        return [
            'front page' => ['/', 'home', 'home'],
            'Route 66 cards' => ['/route-66', 'route66.index', 'route66/index'],
            'a Route 66 site' => ['/route-66/AM-004', 'route66.show', 'route66/show'],
            'Farmers Markets' => ['/farmers-markets', 'markets.index', 'markets/index'],
            'Data Explorer' => ['/data-explorer', 'data.index', 'data/index'],
            'Public Art' => ['/public-art', 'art.index', 'art/index'],
            'Overview' => ['/overview', 'overview.index', 'overview/index'],
            'Earthquakes' => ['/earthquakes', 'earthquakes.index', 'earthquakes/index'],
            'Groundwater' => ['/groundwater', 'groundwater.index', 'groundwater/index'],
            'Air Quality' => ['/air-quality', 'airquality.index', 'airquality/index'],
            'Garden and Grow' => ['/garden-and-grow', 'garden.index', 'garden/index'],
            'Climate Projections' => ['/climate-projections', 'climate.index', 'climate/index'],
        ];
    }

    #[DataProvider('pages')]
    public function test_a_visitor_sees_the_page_when_the_gate_is_off(string $path, string $name, string $component): void
    {
        config(['app.invite_only' => false]);

        $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
        $this->assertSame(url($path), route($name, $name === 'route66.show' ? ['site' => 'AM-004'] : []));
    }

    #[DataProvider('pages')]
    public function test_a_signed_out_visitor_is_sent_to_login_when_the_gate_is_on(string $path): void
    {
        config(['app.invite_only' => true]);

        $this->get($path)->assertRedirect(route('login'));
    }

    #[DataProvider('pages')]
    public function test_a_signed_in_user_sees_the_page_when_the_gate_is_on(string $path, string $name, string $component): void
    {
        config(['app.invite_only' => true]);
        $this->actingAs(User::factory()->create());

        $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
    }

    public function test_a_route_66_site_page_gets_its_id_and_nothing_else(): void
    {
        config(['app.invite_only' => false]);

        $this->get('/route-66/AM-004')->assertInertia(fn (Assert $page) => $page->where('siteId', 'AM-004')->missing('preview'));
    }

    public function test_an_address_that_cannot_be_a_site_id_is_not_found(): void
    {
        config(['app.invite_only' => false]);

        $this->get('/route-66/'.str_repeat('A', 65))->assertNotFound();
        $this->get('/route-66/a%20b')->assertNotFound();
        $this->get('/route-66/a%3Cb')->assertNotFound();
        $this->get('/route-66/AM-004/extra')->assertNotFound();
    }

    public function test_the_preview_switch_is_ignored_outside_the_local_environment(): void
    {
        config(['app.invite_only' => false]);
        $this->assertSame('testing', app()->environment());

        foreach (['/', '/route-66', '/farmers-markets', '/data-explorer', '/public-art', '/overview', '/earthquakes', '/groundwater', '/air-quality', '/garden-and-grow', '/climate-projections', '/route-66/AM-004'] as $path) {
            $this->get($path.'?preview=1')->assertOk()->assertInertia(fn (Assert $page) => $page->missing('preview'));
        }
    }

    public function test_the_old_placeholder_front_page_is_gone(): void
    {
        $this->assertFileDoesNotExist(resource_path('js/pages/welcome.tsx'));
    }

    public function test_every_route_the_pages_call_exists_and_reaches_a_signed_out_visitor(): void
    {
        $reachable = array_merge(config('ziggy.groups.sign_in'), config('ziggy.groups.public'));
        $called = [];

        foreach (['home.tsx', 'route66', 'markets', 'data', 'art', 'overview', 'earthquakes', 'groundwater', 'airquality', 'garden', 'climate'] as $entry) {
            $path = resource_path('js/pages/'.$entry);
            $files = is_dir($path) ? glob($path.'/*.tsx') : [$path];

            foreach ($files as $file) {
                preg_match_all("/route\('([A-Za-z0-9._-]+)'/", file_get_contents($file), $m);
                $called = array_merge($called, $m[1]);
            }
        }

        $this->assertContains('route66.show', $called);
        $this->assertContains('route66.index', $called);

        foreach (array_unique($called) as $name) {
            $this->assertTrue(Route::has($name), "A public page calls route('{$name}'), which does not exist.");
            $this->assertContains($name, $reachable, "route('{$name}') is called by a public page but is not in the sign_in or public group of config/ziggy.php.");
        }
    }

    public function test_every_page_in_the_navigation_is_a_real_route(): void
    {
        preg_match_all("/ready: true, route: '([A-Za-z0-9._-]+)'/", file_get_contents(resource_path('js/components/mrv/navigation.ts')), $m);

        foreach (['home', 'route66.index', 'markets.index', 'data.index', 'art.index', 'overview.index', 'earthquakes.index', 'groundwater.index', 'airquality.index', 'garden.index', 'climate.index'] as $expected) {
            $this->assertContains($expected, $m[1]);
        }
        foreach ($m[1] as $name) {
            $this->assertTrue(Route::has($name), "The navigation links route('{$name}'), which does not exist.");
        }
    }

    // ---- the Route 66 files -------------------------------------------------------------------------------------

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function route66Data(): array
    {
        $dir = public_path('route66/data');

        return [
            json_decode(file_get_contents($dir.'/sites.json'), true, flags: JSON_THROW_ON_ERROR),
            json_decode(file_get_contents($dir.'/media_candidates.json'), true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    public function test_the_route_66_data_is_there_and_complete(): void
    {
        [$sites, $media] = $this->route66Data();

        $this->assertCount(100, $sites['sites']);
        $this->assertSame(100, count(array_unique(array_column($sites['sites'], 'id'))));

        foreach ($sites['sites'] as $site) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9._~-]{1,64}$/', $site['id'], 'a site id the route would refuse');
            foreach (['name', 'community', 'relevance_tier', 'themes', 'alt_names', 'route66_relationship'] as $field) {
                $this->assertArrayHasKey($field, $site, "{$site['id']} lacks {$field}");
            }
        }
        $this->assertArrayHasKey('detailed_candidates_by_site', $media);
    }

    public function test_every_downloaded_route_66_photo_exists_and_is_served_from_the_public_folder(): void
    {
        [, $media] = $this->route66Data();
        $downloaded = 0;

        foreach ($media['detailed_candidates_by_site'] as $siteId => $candidates) {
            foreach ($candidates as $candidate) {
                if (($candidate['download_status'] ?? '') !== 'downloaded') {
                    continue;
                }
                $downloaded++;
                $this->assertStringStartsWith('route66/media/'.$siteId.'/', $candidate['local_path']);
                $this->assertFileExists(public_path($candidate['local_path']), "{$siteId}: {$candidate['local_path']}");
            }
        }

        // 92 photos are listed as downloaded. The media folder also holds ATTRIBUTION.md and one photo no site lists
        // (BA-005/02-goldstones-70-meter-antenna-pia26716.jpg); both are on production too and are copied as they are.
        $this->assertSame(92, $downloaded);
        $this->assertFileExists(public_path('route66/media/ATTRIBUTION.md'));
    }

    public function test_the_front_page_photos_exist(): void
    {
        foreach (['AM-004/02-amboy-crater-below-the-night-sky-52451571479.jpg', 'ND-011/01-route-66-mural-in-needles-california-lccn2013630406.jpg'] as $photo) {
            $this->assertFileExists(public_path('route66/media/'.$photo));
        }
    }

    public function test_development_only_route_66_files_are_not_in_the_public_folder(): void
    {
        $this->assertFileDoesNotExist(public_path('route66/data/media_candidates_pilot.json'));
        $this->assertDirectoryDoesNotExist(public_path('route66/scripts'));
    }
}
