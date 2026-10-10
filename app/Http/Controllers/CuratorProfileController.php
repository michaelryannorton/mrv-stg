<?php

namespace App\Http\Controllers;

use App\Models\CuratorProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A curator's own public profile (Phase 2 slice 1c-iii). There is no id in the URL: both actions
 * always act on the signed-in user's own row, so another person's profile cannot be read or
 * written from here. The row is created on the first save, not on first view.
 */
class CuratorProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $profile = $user->curatorProfile;

        return Inertia::render('curate/profile', [
            'profile' => [
                'display_name' => $profile?->display_name ?? $user->name,
                'blurb' => $profile?->blurb ?? '',
                'is_public' => $profile?->is_public ?? false,
            ],
            'blurb_limit' => CuratorProfile::BLURB_LIMIT,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        // Browsers send a textarea's line breaks as CRLF; count and store them as one character.
        if (is_string($request->input('blurb'))) {
            $request->merge(['blurb' => str_replace("\r\n", "\n", trim($request->input('blurb')))]);
        }

        $data = $request->validate([
            'display_name' => 'required|string|max:100',
            'blurb' => 'nullable|string|max:'.CuratorProfile::BLURB_LIMIT,
            'is_public' => 'boolean',
        ]);

        $request->user()->curatorProfile()->updateOrCreate([], [
            'display_name' => trim($data['display_name']),
            'blurb' => ($data['blurb'] ?? '') === '' ? null : $data['blurb'],
            'is_public' => $data['is_public'] ?? false,
        ]);

        return back()->with('success', 'Your profile was saved.');
    }
}
