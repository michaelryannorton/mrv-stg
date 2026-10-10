<?php

namespace App\Http\Middleware;

use App\Models\Channel;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
                // Lazy, so the permission lookups only run for Inertia responses. The sidebar uses
                // these to show only what the signed-in user can use; the server still enforces access.
                'roles' => fn () => $request->user()?->getRoleNames()->values() ?? [],
                'permissions' => fn () => $request->user()?->getAllPermissions()->pluck('name')->values() ?? [],
            ],
            // Lazy: the public header shows its Channels link only once a channel is active, so
            // there is never a link to an empty list.
            'public_channels' => fn () => Channel::active()->exists(),
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ]);
    }
}
