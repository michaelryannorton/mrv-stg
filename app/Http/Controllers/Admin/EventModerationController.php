<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EventModerationController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->query('status', 'pending');

        $events = Event::query()
            ->with(['venue', 'organizer', 'categories', 'tags', 'eventSources.source'])
            ->when($status === 'pending', fn ($q) => $q->whereIn('editorial_status', ['unreviewed', 'pending_review']))
            ->when($status === 'published', fn ($q) => $q->where('editorial_status', 'published'))
            ->when($status === 'rejected', fn ($q) => $q->where('editorial_status', 'rejected'))
            ->orderBy('start_at')
            ->paginate(25)
            ->withQueryString();

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

    public function approve(Event $event): RedirectResponse
    {
        $event->update([
            'editorial_status' => 'published',
            'status' => $event->status === 'candidate' ? 'scheduled' : $event->status,
            'published_at' => $event->published_at ?? now(),
        ]);

        return back()->with('success', "\"{$event->title}\" approved and published.");
    }

    public function reject(Event $event): RedirectResponse
    {
        $event->update(['editorial_status' => 'rejected']);

        return back()->with('success', "\"{$event->title}\" rejected.");
    }

    public function revert(Event $event): RedirectResponse
    {
        $event->update(['editorial_status' => 'pending_review']);

        return back()->with('success', "\"{$event->title}\" moved back to pending review.");
    }
}
