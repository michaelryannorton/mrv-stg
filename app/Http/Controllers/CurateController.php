<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Event;
use App\Services\Curation\ChannelCuration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The curator workspace (Phase 2 slice 1c-ii). Curators and editors work here, never under /admin.
 * Every handler authorizes the channel through ChannelPolicy first, and the event is always looked
 * up through the channel (never by a bare id), so a curator cannot touch an event outside it.
 */
class CurateController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $user = $request->user();

        $channels = $user->hasRole('administrator')
            ? Channel::query()->where('status', '!=', 'archived')->orderBy('name')->get()
            : $user->curatedChannels()->where('channels.status', '!=', 'archived')->orderBy('channels.name')->get();

        return Inertia::render('curate/index', [
            'channels' => $channels->map(fn (Channel $channel) => [
                'id' => $channel->id,
                'name' => $channel->name,
                'slug' => $channel->slug,
                'short_description' => $channel->short_description,
                'status' => $channel->status,
                'assignment_role' => $channel->pivot?->role,
                'upcoming_count' => $channel->events()->visibleToCurators()->upcoming()->count(),
                'featured_count' => DB::table('event_channels')->where('channel_id', $channel->id)->where('relationship_type', 'featured')->count(),
            ])->values(),
        ]);
    }

    public function show(Request $request, Channel $channel): Response
    {
        Gate::authorize('curate', $channel);

        $tab = $request->query('tab') === 'excluded' ? 'excluded' : 'events';
        $find = trim((string) $request->query('find', ''));

        $events = $tab === 'excluded'
            ? Event::query()->visibleToCurators()->upcoming()
                ->whereIn('events.id', $this->rowsOfType($channel, ['excluded']))
            : $channel->events()->visibleToCurators()->upcoming();

        $page = $events->with(['venue:id,name,city', 'categories:id,name'])
            ->orderBy('events.start_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $states = DB::table('event_channels')
            ->where('channel_id', $channel->id)
            ->whereIn('event_id', $page->pluck('id'))
            ->pluck('relationship_type', 'event_id');

        return Inertia::render('curate/show', [
            'channel' => [
                'id' => $channel->id,
                'name' => $channel->name,
                'slug' => $channel->slug,
                'description' => $channel->description,
                'status' => $channel->status,
                'categories' => $channel->categories()->orderBy('name')->pluck('name'),
            ],
            'tab' => $tab,
            'events' => $page->through(fn (Event $event) => $this->row($event, $states[$event->id] ?? null)),
            'excluded_count' => Event::query()->visibleToCurators()->upcoming()->whereIn('events.id', $this->rowsOfType($channel, ['excluded']))->count(),
            'find' => $find,
            'results' => mb_strlen($find) >= 2 ? $this->search($channel, $find) : [],
        ]);
    }

    public function act(Request $request, Channel $channel, string $event, string $action, ChannelCuration $curation): RedirectResponse
    {
        Gate::authorize('curate', $channel);

        $message = $curation->{$action}($channel, $this->resolveEvent($channel, (int) $event, $action), $request->user());

        return back()->with('success', $message);
    }

    /** The event as the action needs it: reachable through the channel, and visible to curators. */
    private function resolveEvent(Channel $channel, int $id, string $action): Event
    {
        return match ($action) {
            'feature', 'exclude' => $channel->events()->visibleToCurators()->findOrFail($id),
            'unfeature' => Event::visibleToCurators()->whereIn('events.id', $this->rowsOfType($channel, ['featured']))->findOrFail($id),
            'restore' => Event::visibleToCurators()->whereIn('events.id', $this->rowsOfType($channel, ['excluded']))->findOrFail($id),
            'add' => Event::visibleToCurators()->upcoming()->findOrFail($id),
        };
    }

    private function rowsOfType(Channel $channel, array $types)
    {
        return DB::table('event_channels')->select('event_id')->where('channel_id', $channel->id)->whereIn('relationship_type', $types);
    }

    /** Upcoming events outside the channel (no category match, no row) whose title contains the text. */
    private function search(Channel $channel, string $find): array
    {
        $like = '%'.preg_replace('/([!%_])/', '!$1', $find).'%';

        return Event::query()->visibleToCurators()->upcoming()
            ->whereNotIn('events.id', $channel->events()->select('events.id'))
            ->whereNotIn('events.id', DB::table('event_channels')->select('event_id')->where('channel_id', $channel->id))
            ->whereRaw("events.title LIKE ? ESCAPE '!'", [$like])
            ->with(['venue:id,name,city', 'categories:id,name'])
            ->orderBy('events.start_at')
            ->limit(20)
            ->get()
            ->map(fn (Event $event) => $this->row($event, null))
            ->all();
    }

    private function row(Event $event, ?string $state): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'start_at' => $event->start_at?->toIso8601String(),
            'end_at' => $event->end_at?->toIso8601String(),
            'timezone' => $event->timezone,
            'all_day' => $event->all_day,
            'venue' => $event->venue ? ['name' => $event->venue->name, 'city' => $event->venue->city] : null,
            'location' => $event->location_name_override,
            'categories' => $event->categories->pluck('name')->values(),
            'is_public' => $event->editorial_status === 'published' && ! in_array($event->status, ['draft', 'candidate'], true),
            'access_scope' => $event->access_scope,
            'url' => $event->canonical_url,
            'state' => $state ?? 'category',
        ];
    }
}
