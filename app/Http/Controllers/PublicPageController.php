<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public pages ported from the production weather site (docs: logs/2026 1014 1618 Scoping Full Port Of
 * Production Into Laravel.md). They load their data in the browser: Route 66 from the static files in
 * public/route66/data, the others from the weather API (resources/js/lib/mrv/api.ts). Nothing here reads the database.
 */
class PublicPageController extends Controller
{
    public function home(Request $request): Response
    {
        return $this->page($request, 'home');
    }

    public function route66Index(Request $request): Response
    {
        return $this->page($request, 'route66/index');
    }

    public function route66Show(Request $request, string $site): Response
    {
        return $this->page($request, 'route66/show', ['siteId' => $site]);
    }

    public function markets(Request $request): Response
    {
        return $this->page($request, 'markets/index');
    }

    public function dataExplorer(Request $request): Response
    {
        return $this->page($request, 'data/index');
    }

    public function overview(Request $request): Response
    {
        return $this->page($request, 'overview/index');
    }

    public function earthquakes(Request $request): Response
    {
        return $this->page($request, 'earthquakes/index');
    }

    public function groundwater(Request $request): Response
    {
        return $this->page($request, 'groundwater/index');
    }

    public function airQuality(Request $request): Response
    {
        return $this->page($request, 'airquality/index');
    }

    public function city(Request $request): Response
    {
        return $this->page($request, 'city/index');
    }

    public function garden(Request $request): Response
    {
        return $this->page($request, 'garden/index');
    }

    public function climate(Request $request): Response
    {
        return $this->page($request, 'climate/index');
    }

    public function publicArt(Request $request): Response
    {
        return $this->page($request, 'art/index');
    }

    /**
     * `?preview=1` shows production's navigation with every page linked and no account link, so the page can be
     * compared with production's (port/phase-3/tools/compare_pages.mjs). Honoured only in the local environment.
     *
     * @param  array<string, mixed>  $props
     */
    private function page(Request $request, string $component, array $props = []): Response
    {
        if (app()->environment('local') && $request->boolean('preview')) {
            $props['preview'] = true;
        }

        return Inertia::render($component, $props);
    }
}
