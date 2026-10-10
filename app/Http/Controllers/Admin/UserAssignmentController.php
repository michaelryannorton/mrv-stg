<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\CuratorAssignment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Administrator-only: which channels an editor or curator works on (Phase 2 slice 1c-ii).
 * Administrator accounts are not managed here (404), and only people who hold channels.curate can
 * be assigned. Ending an assignment keeps the row, so assigning the same person again reactivates it.
 */
class UserAssignmentController extends Controller
{
    public function store(Request $request, User $user): RedirectResponse
    {
        $this->requireAssignable($user);

        $data = $request->validate([
            'channel_id' => ['required', 'integer', Rule::exists('channels', 'id')->whereNull('deleted_at')->whereNot('status', 'archived')],
            'role' => ['required', Rule::in(CuratorAssignment::ROLES)],
        ]);

        $assignment = CuratorAssignment::firstOrNew(['channel_id' => $data['channel_id'], 'user_id' => $user->id]);
        $assignment->fill(['role' => $data['role'], 'status' => 'active', 'ended_at' => null]);
        if (! $assignment->exists || $assignment->started_at === null || $assignment->getOriginal('status') === 'ended') {
            $assignment->started_at = now();
        }
        $assignment->save();

        return back()->with('success', "{$user->name} now works on \"{$assignment->channel->name}\" as {$data['role']}.");
    }

    public function update(Request $request, User $user, Channel $channel): RedirectResponse
    {
        $this->requireAssignable($user);
        $assignment = $this->activeAssignment($user, $channel);

        $data = $request->validate(['role' => ['required', Rule::in(CuratorAssignment::ROLES)]]);
        $assignment->update($data);

        return back()->with('success', "{$user->name} is now {$data['role']} on \"{$channel->name}\".");
    }

    public function destroy(User $user, Channel $channel): RedirectResponse
    {
        $this->requireAssignable($user);
        $assignment = $this->activeAssignment($user, $channel);

        $assignment->update(['status' => 'ended', 'ended_at' => now()]);

        return back()->with('success', "{$user->name} no longer works on \"{$channel->name}\".");
    }

    private function requireAssignable(User $user): void
    {
        abort_if($user->hasRole('administrator'), 404);
        abort_unless($user->can('channels.curate'), 422, 'Only editors and curators can be assigned to channels.');
    }

    private function activeAssignment(User $user, Channel $channel): CuratorAssignment
    {
        return CuratorAssignment::where('user_id', $user->id)->where('channel_id', $channel->id)->where('status', 'active')->firstOrFail();
    }
}
