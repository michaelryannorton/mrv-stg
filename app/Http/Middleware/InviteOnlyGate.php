<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a site private to people who have an account (used on staging, where nothing is ready to be
 * shown). While `app.invite_only` is on, a signed-out visitor is sent to the login page for every
 * address except the ones that sign-in itself needs: login, password reset and the invitation page.
 * Every response that passes through here, including those pages and the redirect, is marked
 * noindex. (An address that matches no route is a plain 404 before any web middleware runs.) With
 * the setting off (the default, and the case locally, in tests and in any production deploy) this
 * does nothing.
 *
 * To open another page to signed-out visitors later (the real front page, for instance), add its
 * route name to OPEN_ROUTES.
 */
class InviteOnlyGate
{
    /** Route names a signed-out visitor may reach while the gate is on. */
    private const OPEN_ROUTES = [
        'login',
        'password.request',
        'password.email',
        'password.reset',
        'password.store',
        'invitation.show',
        'invitation.store',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.invite_only')) {
            return $next($request);
        }

        $response = $this->mayPass($request) ? $next($request) : redirect()->route('login');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function mayPass(Request $request): bool
    {
        if ($request->user() !== null) {
            return true;
        }

        // The login form's POST is the one open route without a name; /up is the framework health check.
        return $request->routeIs(self::OPEN_ROUTES)
            || ($request->isMethod('POST') && $request->is('login'))
            || $request->is('up');
    }
}
