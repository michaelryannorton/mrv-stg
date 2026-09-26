<?php

namespace App\Http\Controllers;

use App\Models\Audience;
use App\Models\Category;
use App\Models\Event;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'when' => 'nullable|in:today,tonight,this_weekend,next_7_days',
            'category' => 'nullable|string|exists:categories,slug',
            'audience' => 'nullable|string|exists:audiences,slug',
            'free' => 'nullable|boolean',
            'city' => 'nullable|string|max:100',
        ]);

        $events = Event::published()
            ->with(['venue', 'organizer', 'categories', 'tags', 'audiences'])
            ->when($filters['category'] ?? null, fn ($q, $slug) => $q->whereHas('categories', fn ($q) => $q->where('slug', $slug)))
            ->when($filters['audience'] ?? null, fn ($q, $slug) => $q->whereHas('audiences', fn ($q) => $q->where('slug', $slug)))
            ->when($filters['free'] ?? null, fn ($q) => $q->where('is_free', true))
            ->when($filters['city'] ?? null, fn ($q, $city) => $q->whereHas('venue', fn ($q) => $q->where('city', $city)))
            ->when($filters['when'] ?? null, function ($q, $when) {
                // "Today"/"tonight"/"this weekend" mean the calendar day in the region's own
                // timezone, not the server's. `start_at` is stored as a true UTC instant, so the
                // boundary must be computed in Pacific time first, then converted to UTC for the
                // comparison — comparing raw now() (server/UTC) against Pacific wall-clock
                // boundaries would be off by the UTC/Pacific offset for a large part of every day.
                $now = now('America/Los_Angeles');

                match ($when) {
                    'today' => $q->whereBetween('start_at', [$now->copy()->startOfDay()->utc(), $now->copy()->endOfDay()->utc()]),
                    'tonight' => $q->whereBetween('start_at', [$now->copy()->setTime(17, 0)->utc(), $now->copy()->endOfDay()->utc()]),
                    'this_weekend' => $q->whereBetween('start_at', [$now->copy()->startOfWeek()->addDays(5)->utc(), $now->copy()->startOfWeek()->addDays(7)->utc()]),
                    'next_7_days' => $q->whereBetween('start_at', [$now->utc(), $now->copy()->addDays(7)->utc()]),
                };
            })
            ->orderBy('start_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('events/index', [
            'events' => $events,
            'filters' => $filters,
            'categories' => Category::orderBy('name')->get(['id', 'name', 'slug']),
            'audiences' => Audience::orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }
}
