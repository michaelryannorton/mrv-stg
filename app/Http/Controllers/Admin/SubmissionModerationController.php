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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
                'spam' => PublicSubmission::where('status', 'spam')->count(),
            ],
        ]);
    }

    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => 'required|in:approve,reject,spam',
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:public_submissions,id',
        ]);

        $submissions = PublicSubmission::whereIn('id', $data['ids'])->get();
        $done = 0;
        $failed = 0;

        foreach ($submissions as $submission) {
            try {
                $changed = match ($data['action']) {
                    'approve' => $this->approve($submission, $request->user()->id),
                    'reject' => $this->mark($submission, 'rejected', $request->user()->id),
                    'spam' => $this->mark($submission, 'spam', $request->user()->id),
                };
            } catch (\Throwable $e) {
                // One bad row does not stop the rest, and nothing of it is saved (approve() is one transaction).
                $failed++;
                Log::error("Submission {$submission->id} could not be {$data['action']}d: ".$e->getMessage());

                continue;
            }

            $done += $changed ? 1 : 0;
        }

        $verb = match ($data['action']) {
            'approve' => 'approved and published',
            'reject' => 'rejected',
            'spam' => 'marked as spam',
        };

        $message = "{$done} ".Str::plural('submission', $done)." {$verb}.";

        if ($failed > 0) {
            $message .= " {$failed} could not be processed and were left as they were (see the log).";
        }

        return back()->with($failed > 0 ? 'error' : 'success', $message);
    }

    /**
     * One transaction: the event, its source link and the review mark are saved together or not at
     * all. A submission already approved is skipped (returns false), so a second click or a second
     * administrator cannot publish it twice.
     */
    private function approve(PublicSubmission $submission, int $reviewerId): bool
    {
        return DB::transaction(function () use ($submission, $reviewerId): bool {
            $submission = PublicSubmission::query()->lockForUpdate()->findOrFail($submission->id);

            if ($submission->status === 'approved') {
                return false;
            }

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

            return true;
        });
    }

    private function mark(PublicSubmission $submission, string $status, int $reviewerId): bool
    {
        $submission->update([
            'status' => $status,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
        ]);

        return true;
    }
}
