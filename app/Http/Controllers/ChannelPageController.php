<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\CuratorAssignment;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public channel landing pages (Phase 2 slice 1d-ii). Everything here is built from explicit
 * lists of fields, never from a model or a user record, so a column added later cannot leak.
 * Membership comes from Event::scopeInChannel(); visibility from Event::published().
 */
class ChannelPageController extends Controller
{
    private const FEED_PER_PAGE = 20;

    private const PICKS_LIMIT = 6;

    private const CARD_LIMIT = 3;

    private const ROLE_LABELS = ['lead' => 'Lead curator', 'curator' => 'Curator', 'contributor' => 'Contributor'];

    public function index(): Response
    {
        $channels = Channel::active()->orderBy('sort_order')->orderBy('name')->get();

        $leads = CuratorAssignment::publiclyListed()->byRank()
            ->where('curator_assignments.role', 'lead')
            ->whereIn('curator_assignments.channel_id', $channels->pluck('id'))
            ->with('user.curatorProfile')
            ->get()
            ->unique('channel_id')
            ->keyBy('channel_id');

        return Inertia::render('channels/index', [
            'channels' => $channels->map(fn (Channel $channel) => [
                'name' => $channel->name,
                'slug' => $channel->slug,
                'short_description' => $channel->short_description,
                'lead_curator' => $leads->get($channel->id)?->user->curatorProfile->display_name,
                'upcoming_count' => $this->publicEvents($channel)->count(),
            ])->values(),
        ]);
    }

    public function show(Request $request, Channel $channel): Response
    {
        // A channel that is not active is not public. Only an administrator may look at it, and
        // the status is sent to the page only in that case.
        $preview = null;

        if ($channel->status !== 'active') {
            abort_unless($request->user()?->hasRole('administrator'), 404);
            $preview = $channel->status;
        }

        $featuredIds = DB::table('event_channels')
            ->where('channel_id', $channel->id)
            ->where('relationship_type', 'featured')
            ->pluck('event_id');

        $picks = $this->publicEvents($channel)
            ->whereIn('events.id', $featuredIds)
            ->orderBy('events.start_at')
            ->limit(self::PICKS_LIMIT)
            ->get();

        $feed = $this->publicEvents($channel)
            ->orderBy('events.start_at')
            ->paginate(self::FEED_PER_PAGE)
            ->withQueryString();

        return Inertia::render('channels/show', [
            'channel' => [
                'name' => $channel->name,
                'slug' => $channel->slug,
                'description' => $channel->description,
                'meta_description' => $this->metaDescription($channel),
            ],
            'preview' => $preview,
            'curators' => $this->curators($channel),
            'picks' => $picks->map(fn (Event $event) => $this->card($event, true))->values(),
            'events' => $feed->through(fn (Event $event) => $this->card($event, $featuredIds->contains($event->id))),
        ]);
    }

    /** Published, upcoming members of the channel, with what the event card needs loaded. */
    private function publicEvents(Channel $channel)
    {
        return $channel->events()->published()->upcoming()
            ->with(['venue:id,name,city', 'organizer:id,name', 'categories:id,name,slug', 'tags:id,name,slug', 'audiences:id,name,slug']);
    }

    /** @return Collection<int, array{display_name: string, role: string, blurb: ?string, since: ?string}> */
    private function curators(Channel $channel): Collection
    {
        return CuratorAssignment::publiclyListed()->byRank()
            ->where('curator_assignments.channel_id', $channel->id)
            ->with('user.curatorProfile')
            ->limit(self::CARD_LIMIT)
            ->get()
            ->map(fn (CuratorAssignment $assignment) => [
                'display_name' => $assignment->user->curatorProfile->display_name,
                'role' => self::ROLE_LABELS[$assignment->role] ?? 'Curator',
                'blurb' => $assignment->user->curatorProfile->blurb,
                'since' => $assignment->started_at?->format('F Y'),
            ])
            ->values();
    }

    private function metaDescription(Channel $channel): ?string
    {
        $text = $channel->short_description ?: Str::limit(preg_replace('/\s+/', ' ', trim((string) $channel->description)), 160, '...');

        return $text !== '' ? $text : null;
    }

    private function card(Event $event, bool $isPick): array
    {
        $taxonomy = fn ($items) => $items->map(fn ($item) => ['id' => $item->id, 'name' => $item->name, 'slug' => $item->slug])->values();

        return [
            'id' => $event->id,
            'uuid' => $event->uuid,
            'title' => $event->title,
            'slug' => $event->slug,
            'short_description' => $event->short_description,
            'start_at' => $event->start_at,
            'end_at' => $event->end_at,
            'timezone' => $event->timezone,
            'all_day' => $event->all_day,
            'is_free' => $event->is_free,
            'details_url' => $event->details_url,
            'venue' => $event->venue ? ['id' => $event->venue->id, 'name' => $event->venue->name, 'city' => $event->venue->city] : null,
            'organizer' => $event->organizer ? ['id' => $event->organizer->id, 'name' => $event->organizer->name] : null,
            'categories' => $taxonomy($event->categories),
            'tags' => $taxonomy($event->tags),
            'audiences' => $taxonomy($event->audiences),
            'is_pick' => $isPick,
        ];
    }
}
