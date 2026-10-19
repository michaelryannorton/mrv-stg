<?php

use App\Http\Controllers\StatusHealthController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\InviteOnlyGate;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The status dashboard's health endpoint. Registered here, outside the `web` group, so polling starts no session,
        // sets no cookie and never meets InviteOnlyGate (which is appended to that group). The token is its protection.
        then: function () {
            Route::get('/_status/health', StatusHealthController::class);
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            InviteOnlyGate::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // The invite-only gate must see a signed-out visitor before the auth middleware (which would
        // redirect without the noindex header) and before route-model binding (which would answer an
        // unknown channel with a 404). Run it right after the session has started.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: InviteOnlyGate::class);

        // Decide authorization before route-model binding, so a user without access gets a 403 for
        // every event id and cannot tell which ids exist from 404-versus-403.
        foreach ([RoleMiddleware::class, PermissionMiddleware::class, RoleOrPermissionMiddleware::class] as $authorizer) {
            $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: $authorizer);
        }
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
