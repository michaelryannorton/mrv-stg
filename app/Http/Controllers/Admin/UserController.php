<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Administrator-only user invitations (Phase 2 slice 1c-i; the whole /admin group requires the
 * administrator role). Only the editor and curator roles can be invited, so no one but the
 * existing administrator(s) can ever send an invitation. Administrator accounts are not managed
 * here: the page lists them read-only.
 */
class UserController extends Controller
{
    public const INVITABLE_ROLES = ['editor', 'curator'];

    /** Minimum seconds between two invitation emails to the same person. */
    private const RESEND_THROTTLE = 60;

    public function index(): Response
    {
        $users = User::query()
            ->with('roles:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->row($user));

        return Inertia::render('admin/users/index', ['users' => $users]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/users/create', ['roles' => self::INVITABLE_ROLES]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'role' => ['required', Rule::in(self::INVITABLE_ROLES)],
        ]);

        $existing = User::where('email', $data['email'])->first();
        if ($existing) {
            throw ValidationException::withMessages([
                'email' => [$existing->isPending()
                    ? 'This person already has a pending invitation. Use Resend on the Users page.'
                    : 'An account with this email already exists.'],
            ]);
        }

        // Account, role, token and email are one unit: if the email cannot be sent (for example
        // while Postmark has not approved the recipient's domain), nothing is kept.
        try {
            $user = DB::transaction(function () use ($data, $request) {
                $user = User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => Hash::make(Str::random(64)),
                ]);
                $user->forceFill(['invited_at' => now(), 'invited_by_user_id' => $request->user()->id])->save();
                $user->assignRole($data['role']);

                $this->sendInvitation($user, $request->user(), $data['role']);

                return $user;
            });
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('error', $this->sendFailureMessage($e, 'No account was created.'));
        }

        return redirect()->route('admin.users.index')->with('success', "Invitation sent to {$user->email}.");
    }

    public function edit(User $user): Response
    {
        abort_if($this->isAdministrator($user), 404);

        $user->load(['roles:id,name', 'invitedBy:id,name']);

        return Inertia::render('admin/users/edit', [
            'user' => [...$this->row($user), 'role' => $user->roles->first()?->name, 'invited_by' => $user->invitedBy?->name],
            'roles' => self::INVITABLE_ROLES,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_if($this->isAdministrator($user), 404);

        $data = $request->validate(['role' => ['required', Rule::in(self::INVITABLE_ROLES)]]);

        $user->syncRoles([$data['role']]);

        return redirect()->route('admin.users.index')->with('success', "{$user->name} is now a {$data['role']}.");
    }

    public function resend(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->isPending(), 422, 'Only a pending invitation can be resent.');

        if ($user->invited_at->gt(now()->subSeconds(self::RESEND_THROTTLE))) {
            return back()->with('error', 'An invitation was sent a moment ago. Wait a minute before resending.');
        }

        try {
            $this->sendInvitation($user, $request->user(), $user->roles->first()?->name ?? 'curator');
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', $this->sendFailureMessage($e, 'The invitation was not sent.'));
        }

        $user->forceFill(['invited_at' => now(), 'invited_by_user_id' => $request->user()->id])->save();

        return back()->with('success', "Invitation resent to {$user->email}.");
    }

    public function destroy(User $user): RedirectResponse
    {
        abort_unless($user->isPending(), 422, 'Only a pending invitation can be revoked.');

        DB::transaction(function () use ($user) {
            Password::broker('invitations')->deleteToken($user);
            $user->delete();
        });

        return redirect()->route('admin.users.index')->with('success', "Invitation for {$user->email} revoked.");
    }

    private function sendInvitation(User $user, User $inviter, string $role): void
    {
        $token = Password::broker('invitations')->createToken($user);

        $user->notify(new UserInvitation($token, $inviter->name, $role));
    }

    private function sendFailureMessage(Throwable $e, string $outcome): string
    {
        return 'The invitation email could not be sent. '.$outcome.' Reason: '.Str::limit($e->getMessage(), 200);
    }

    private function isAdministrator(User $user): bool
    {
        return $user->hasRole('administrator');
    }

    private function row(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name')->values(),
            'status' => $user->isPending() ? 'invited' : 'active',
            'invited_at' => $user->invited_at?->toIso8601String(),
            'editable' => ! $this->isAdministrator($user),
        ];
    }
}
