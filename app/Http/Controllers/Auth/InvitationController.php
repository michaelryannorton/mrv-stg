<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where an invited curator or editor sets a password (Phase 2 slice 1c). Uses the `invitations`
 * password broker, which hashes the token, enforces the 7-day expiry and deletes the token on use.
 * An unknown, used or expired link all produce the same answer, so the page never reveals whether
 * an email has an account.
 */
class InvitationController extends Controller
{
    private const INVALID = 'This invitation link is no longer valid. Ask the administrator to send a new one.';

    public function show(Request $request, string $token): Response
    {
        $email = Str::lower((string) $request->query('email'));
        $user = User::where('email', $email)->first();
        $broker = Password::broker('invitations');

        $valid = $user !== null && $user->isPending() && $broker->tokenExists($user, $token);

        return Inertia::render('auth/accept-invitation', [
            'valid' => $valid,
            'token' => $token,
            'email' => $valid ? $user->email : '',
            'name' => $valid ? $user->name : '',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => Str::lower((string) $request->input('email'))]);

        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $status = Password::broker('invitations')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'invitation_accepted_at' => now(),
                ])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return to_route('login')->with('status', 'Your password is set. Log in to continue.');
        }

        throw ValidationException::withMessages(['email' => [self::INVALID]]);
    }
}
