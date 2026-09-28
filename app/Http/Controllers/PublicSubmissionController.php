<?php

namespace App\Http\Controllers;

use App\Models\PublicSubmission;
use App\Services\EventExtractor;
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
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:500',
            'description' => 'nullable|string',
            'start_at' => 'required|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
            'timezone' => 'required|string|max:64',
            'all_day' => 'boolean',
            'location_name_override' => 'nullable|string|max:255',
            'address_override' => 'nullable|string',
            'canonical_url' => 'nullable|url|max:2048',
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
