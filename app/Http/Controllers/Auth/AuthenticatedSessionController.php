<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AuthenticatedSessionController extends Controller
{
    /**
     * Show the login page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): SymfonyResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // Editors and curators work in the curator workspace; everyone else lands on the dashboard.
        $user = $request->user();
        $home = $user->can('channels.curate') && ! $user->hasRole('administrator') ? 'curate.index' : 'dashboard';

        // A full page load (Inertia::location), not a client-side visit: the route list in the page
        // is different for a signed-in user, and only a fresh load replaces it. Other requests get
        // an ordinary redirect.
        return Inertia::location(redirect()->intended(route($home, absolute: false)));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): SymfonyResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // A full page load, so the page starts again with the signed-out route list.
        return Inertia::location(redirect('/'));
    }
}
