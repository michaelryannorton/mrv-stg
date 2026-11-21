<?php

namespace App\Http\Controllers;

use App\Models\PublicSubmission;
use App\Services\EventExtractor;
use App\Support\SubmissionFormGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Section 5 of the Phase 1 plan: "Submit an Event," reachable without an account. Deliberately
 * writes to public_submissions, never events — anonymous/unverified input never touches the
 * canonical table before Michael has looked at it (Admin\SubmissionModerationController).
 */
class PublicSubmissionController extends Controller
{
    public function create(Request $request): Response
    {
        $sourceUrl = $request->query('url');
        $prefilled = $sourceUrl ? EventExtractor::extractFromUrl($sourceUrl) : [];

        return Inertia::render('submit/create', [
            'prefilled' => $prefilled,
            'sourceUrl' => $sourceUrl,
            'formToken' => SubmissionFormGuard::issue(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Before validation, so a script gets no hints about which field it got wrong.
        switch (SubmissionFormGuard::check($request->input('homepage_url'), $request->input('form_token'))) {
            case SubmissionFormGuard::BOT:
                // Looks like a success and stores nothing.
                return redirect()->route('submit.create')->with('success', 'Thanks — your event has been submitted for review.');
            case SubmissionFormGuard::INVALID:
                return redirect()->route('submit.create')->with('error', 'The form could not be checked. What you entered is still in the form. Please reload the page and send it again.');
            case SubmissionFormGuard::TOO_FAST:
                return redirect()->route('submit.create')->with('error', 'That was sent very quickly. What you entered is still in the form. Please wait a few seconds and send it again.');
        }

        // Events from one year back to three years ahead: wide enough for any real listing, narrow
        // enough to catch a mistyped year.
        $earliest = now()->subYear()->toDateString();
        $latest = now()->addYears(3)->toDateString().' 23:59:59';

        $data = $request->validate([
            'title' => 'required|string|max:500',
            'description' => 'nullable|string|max:10000',
            'start_at' => ['required', 'date', 'after_or_equal:'.$earliest, 'before_or_equal:'.$latest],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at', 'after_or_equal:'.$earliest, 'before_or_equal:'.$latest],
            'timezone' => 'required|string|timezone',
            'all_day' => 'boolean',
            'location_name_override' => 'nullable|string|max:255',
            'address_override' => 'nullable|string|max:500',
            'canonical_url' => 'nullable|url:http,https|max:2048',
            'is_free' => 'boolean',
            'price_min' => 'nullable|numeric|min:0',
            'price_max' => 'nullable|numeric|min:0',
            // Optional, invited — "especially if you'd like to help curate this list" (plan §3),
            // not required to submit.
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
        ]);

        $name = $data['name'] ?? null;
        $email = $data['email'] ?? null;
        $sourceUrl = $data['canonical_url'] ?? null;
        unset($data['name'], $data['email']);

        PublicSubmission::create([
            'submission_type' => 'event',
            'name' => $name,
            'email' => $email,
            'source_url' => $sourceUrl,
            'submitted_data' => $data,
            'status' => 'pending',
        ]);

        return redirect()->route('submit.create')->with('success', 'Thanks — your event has been submitted for review.');
    }
}
