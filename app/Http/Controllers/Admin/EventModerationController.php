<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class EventModerationController extends Controller
{
    /**
     * No pagination here on purpose: the table view supports client-side search/sort/filter and
     * select-all-and-bulk-act, which all need the full filtered set in hand rather than one page
     * of it. The limit is a defensive cap, not an expected ceiling at Phase 1 volumes.
     */
    public function index(Request $request): Response
    {
        $status = $request->query('status', 'pending');

        $events = Event::query()
            ->with(['venue', 'organizer', 'categories', 'tags', 'eventSources.source'])
            ->when($status === 'pending', fn ($q) => $q->whereIn('editorial_status', ['unreviewed', 'pending_review']))
            ->when($status === 'published', fn ($q) => $q->where('editorial_status', 'published'))
            ->when($status === 'rejected', fn ($q) => $q->where('editorial_status', 'rejected'))
            ->orderBy('start_at')
            ->limit(1000)
            ->get();

        return Inertia::render('admin/events/index', [
            'events' => $events,
            'status' => $status,
            'counts' => [
                'pending' => Event::whereIn('editorial_status', ['unreviewed', 'pending_review'])->count(),
                'published' => Event::where('editorial_status', 'published')->count(),
                'rejected' => Event::where('editorial_status', 'rejected')->count(),
            ],
        ]);
    }

    /**
     * One endpoint for both a single-row action button and a multi-select bulk bar — a row button
     * just calls this with a one-element ids array, so there's one code path to keep correct.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => 'required|in:approve,reject,revert',
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:events,id',
        ]);

        $events = Event::whereIn('id', $data['ids'])->get();

        foreach ($events as $event) {
            match ($data['action']) {
                'approve' => $this->approveEvent($event),
                'reject' => $this->rejectEvent($event),
                'revert' => $this->revertEvent($event),
            };
        }

        $verb = match ($data['action']) {
            'approve' => 'approved and published',
            'reject' => 'rejected',
            'revert' => 'moved back to pending review',
        };

        $count = $events->count();

        return back()->with('success', "{$count} ".Str::plural('event', $count)." {$verb}.");
    }

    private function approveEvent(Event $event): void
    {
        $event->update([
            'editorial_status' => 'published',
            'status' => $event->status === 'candidate' ? 'scheduled' : $event->status,
            'published_at' => $event->published_at ?? now(),
        ]);
    }

    private function rejectEvent(Event $event): void
    {
        $event->update(['editorial_status' => 'rejected']);
    }

    private function revertEvent(Event $event): void
    {
        $event->update(['editorial_status' => 'pending_review']);
    }
}
