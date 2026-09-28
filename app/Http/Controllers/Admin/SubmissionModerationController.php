<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\PublicSubmission;
use App\Models\Source;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Michael's review step for section 5's "Submit an Event" form — public_submissions rows never
 * reach the public calendar on their own. Approving here is itself the moderation decision (same
 * model as the clipper's "publish now": the form Michael is reviewing before acting on it *is*
 * the gate), so an approved submission's event goes straight to published rather than landing in
 * the separate ingestion review queue for a second look.
 */
class SubmissionModerationController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->query('status', 'pending');

        $submissions = PublicSubmission::query()
            ->where('status', $status)
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        return Inertia::render('admin/submissions/index', [
            'submissions' => $submissions,
            'status' => $status,
            'counts' => [
                'pending' => PublicSubmission::where('status', 'pending')->count(),
                'approved' => PublicSubmission::where('status', 'approved')->count(),
                'rejected' => PublicSubmission::where('status', 'rejected')->count(),
            ],
        ]);
    }

    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => 'required|in:approve,reject',
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:public_submissions,id',
        ]);

        $submissions = PublicSubmission::whereIn('id', $data['ids'])->get();

        foreach ($submissions as $submission) {
            match ($data['action']) {
                'approve' => $this->approve($submission, $request->user()->id),
                'reject' => $this->reject($submission, $request->user()->id),
            };
        }

        $verb = $data['action'] === 'approve' ? 'approved and published' : 'rejected';
        $count = $submissions->count();

        return back()->with('success', "{$count} ".Str::plural('submission', $count)." {$verb}.");
    }

    private function approve(PublicSubmission $submission, int $reviewerId): void
    {
        $fields = $submission->submitted_data;

        $event = Event::create([
            'title' => $fields['title'],
            'description' => $fields['description'] ?? null,
            'start_at' => Carbon::parse($fields['start_at'], $fields['timezone']),
            'end_at' => isset($fields['end_at']) ? Carbon::parse($fields['end_at'], $fields['timezone']) : null,
            'timezone' => $fields['timezone'],
            'all_day' => $fields['all_day'] ?? false,
            'location_name_override' => $fields['location_name_override'] ?? null,
            'address_override' => $fields['address_override'] ?? null,
            'canonical_url' => $fields['canonical_url'] ?? null,
            'is_free' => $fields['is_free'] ?? null,
            'price_min' => $fields['price_min'] ?? null,
            'price_max' => $fields['price_max'] ?? null,
            'slug' => Str::slug($fields['title']).'-'.Str::random(6),
            'created_by_user_id' => $reviewerId,
            'status' => 'scheduled',
            'editorial_status' => 'published',
            'published_at' => now(),
        ]);

        $source = Source::firstOrCreate(
            ['source_type' => 'public_submission'],
            ['name' => 'Public submissions', 'collector_type' => 'manual', 'trust_level' => 'review_required'],
        );

        EventSource::create([
            'event_id' => $event->id,
            'source_id' => $source->id,
            'source_url' => $submission->source_url,
            'first_seen_at' => $submission->created_at,
            'last_seen_at' => now(),
            'is_primary' => true,
        ]);

        $submission->update([
            'status' => 'approved',
            'event_id' => $event->id,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
        ]);
    }

    private function reject(PublicSubmission $submission, int $reviewerId): void
    {
        $submission->update([
            'status' => 'rejected',
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
        ]);
    }
}
