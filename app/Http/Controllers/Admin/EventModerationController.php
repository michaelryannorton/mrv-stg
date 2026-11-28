<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\ReviewQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class EventModerationController extends Controller
{
    private const PENDING = ['unreviewed', 'pending_review'];

    private const TABS = ['pending', 'past', 'published', 'rejected'];

    /**
     * The queue as rows, one per series (App\Support\ReviewQueue). Pending holds what has not ended,
     * soonest first; pending events that are over move to "Past", where nothing happens to them. No
     * pagination and no cut: search, filters and select-all work on the whole tab in the browser.
     */
    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), self::TABS, true) ? $request->query('status') : 'pending';

        $events = $this->tab($status)
            ->with(ReviewQueue::RELATIONS)
            ->orderBy('events.start_at', $status === 'past' ? 'desc' : 'asc')
            ->orderBy('events.id')
            ->get();

        return Inertia::render('admin/events/index', [
            'rows' => (new ReviewQueue)->rows($events, in_array($status, ['pending', 'past'], true) ? $this->approvedSeries() : []),
            'status' => $status,
            'counts' => collect(self::TABS)->mapWithKeys(fn (string $tab) => [$tab => $this->tab($tab)->count()]),
        ]);
    }

    private function tab(string $status): Builder
    {
        return match ($status) {
            'pending' => Event::query()->whereIn('editorial_status', self::PENDING)->upcoming(),
            'past' => Event::query()->whereIn('editorial_status', self::PENDING)->ended(),
            default => Event::query()->where('editorial_status', $status),
        };
    }

    /** @return array<string, true> the series that already have a published date */
    private function approvedSeries(): array
    {
        return Event::query()
            ->where('editorial_status', 'published')
            ->join('event_sources', 'event_sources.event_id', '=', 'events.id')
            ->toBase()
            ->get(['event_sources.source_id', 'events.title'])
            ->mapWithKeys(fn (object $row) => [ReviewQueue::seriesKey($row->source_id, $row->title) => true])
            ->all();
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
