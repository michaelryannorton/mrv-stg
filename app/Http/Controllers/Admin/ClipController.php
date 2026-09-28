<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Audience;
use App\Models\Category;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\Venue;
use App\Services\EventExtractor;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Section 6 of the Phase 1 plan: a bookmarklet plus one authenticated route. No separate
 * extension auth, no scoped tokens — the bookmarklet just opens this route in Michael's own
 * logged-in browser tab, passing along whatever page he was looking at.
 */
class ClipController extends Controller
{
    public function create(Request $request): Response
    {
        $sourceUrl = $request->query('url');
        $prefilled = [];

        if ($sourceUrl) {
            $prefilled = $this->extractFrom($sourceUrl);
        }

        return Inertia::render('admin/clip/create', [
            'prefilled' => $prefilled,
            'sourceUrl' => $sourceUrl,
            'clipUrl' => url('/admin/clip'),
            ...$this->lookupLists(),
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
            'venue_id' => 'nullable|integer|exists:venues,id',
            'organizer_id' => 'nullable|integer|exists:organizations,id',
            'location_name_override' => 'nullable|string|max:255',
            'address_override' => 'nullable|string',
            'canonical_url' => 'nullable|url|max:2048',
            'is_free' => 'boolean',
            'price_min' => 'nullable|numeric|min:0',
            'price_max' => 'nullable|numeric|min:0',
            'category_ids' => 'array',
            'category_ids.*' => 'integer|exists:categories,id',
            'tag_ids' => 'array',
            'tag_ids.*' => 'integer|exists:tags,id',
            'audience_ids' => 'array',
            'audience_ids.*' => 'integer|exists:audiences,id',
            'publish_now' => 'boolean',
        ]);

        $categoryIds = $data['category_ids'] ?? [];
        $tagIds = $data['tag_ids'] ?? [];
        $audienceIds = $data['audience_ids'] ?? [];
        $publishNow = $data['publish_now'] ?? false;
        unset($data['category_ids'], $data['tag_ids'], $data['audience_ids'], $data['publish_now']);

        $data['start_at'] = Carbon::parse($data['start_at'], $data['timezone']);
        $data['end_at'] = isset($data['end_at']) ? Carbon::parse($data['end_at'], $data['timezone']) : null;
        $data['slug'] = Str::slug($data['title']).'-'.Str::random(6);
        $data['created_by_user_id'] = $request->user()->id;

        if ($publishNow) {
            $data['status'] = 'scheduled';
            $data['editorial_status'] = 'published';
            $data['published_at'] = now();
        } else {
            $data['status'] = 'draft';
            $data['editorial_status'] = 'unreviewed';
        }

        $event = Event::create($data);
        $event->categories()->sync($categoryIds);
        $event->tags()->sync($tagIds);
        $event->audiences()->sync($audienceIds);

        $message = $publishNow ? "\"{$event->title}\" clipped and published." : "\"{$event->title}\" saved as a draft.";

        return redirect()->route('admin.events.edit', $event)->with('success', $message);
    }

    /**
     * The "my drafts" view (section 6): clips saved with the draft checkbox, scoped to the
     * clipping user so parked, not-yet-finished captures don't get lost among everything else.
     */
    public function drafts(Request $request): Response
    {
        $drafts = Event::query()
            ->where('status', 'draft')
            ->where('created_by_user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'start_at', 'timezone', 'created_at']);

        return Inertia::render('admin/drafts/index', [
            'drafts' => $drafts,
        ]);
    }

    public function publishDraft(Event $event): RedirectResponse
    {
        $event->update([
            'status' => 'scheduled',
            'editorial_status' => 'published',
            'published_at' => $event->published_at ?? now(),
        ]);

        return back()->with('success', "\"{$event->title}\" published.");
    }

    private function extractFrom(string $sourceUrl): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; MRVCommunityClipper/1.0)'])
                ->timeout(10)
                ->get($sourceUrl);
        } catch (\Throwable) {
            return [];
        }

        if (! $response->successful() || ! str_contains($response->header('Content-Type', ''), 'html')) {
            return [];
        }

        return EventExtractor::extract($response->body(), $sourceUrl);
    }

    private function lookupLists(): array
    {
        return [
            'venues' => Venue::orderBy('name')->get(['id', 'name', 'city']),
            'organizations' => Organization::orderBy('name')->get(['id', 'name']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'tags' => Tag::orderBy('name')->get(['id', 'name']),
            'audiences' => Audience::orderBy('name')->get(['id', 'name']),
        ];
    }
}
