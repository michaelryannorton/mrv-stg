<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administrator-only channel management (Phase 2 slice 1b; the whole /admin group requires the
 * administrator role). A channel groups events by linked categories plus explicit picks; see
 * Event::scopeInChannel(). There is no delete: a channel that is no longer wanted is archived.
 */
class ChannelController extends Controller
{
    public function index(): Response
    {
        $channels = Channel::query()
            ->with('categories:id,name')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Channel $channel) => [
                'id' => $channel->id,
                'name' => $channel->name,
                'slug' => $channel->slug,
                'short_description' => $channel->short_description,
                'status' => $channel->status,
                'categories' => $channel->categories->sortBy('name')->values()->map(fn ($c) => ['id' => $c->id, 'name' => $c->name]),
                'events_count' => $channel->events()->count(),
                'published_events_count' => $channel->events()->published()->count(),
            ]);

        return Inertia::render('admin/channels/index', ['channels' => $channels]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/channels/create', [
            'categories' => $this->categoryOptions(),
            'statuses' => Channel::STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $channel = Channel::create($this->validated($request));
        $channel->categories()->sync($request->input('category_ids', []));

        return redirect()->route('admin.channels.index')->with('success', "Channel \"{$channel->name}\" created.");
    }

    public function edit(Channel $channel): Response
    {
        return Inertia::render('admin/channels/edit', [
            'channel' => [
                'id' => $channel->id,
                'name' => $channel->name,
                'slug' => $channel->slug,
                'description' => $channel->description,
                'short_description' => $channel->short_description,
                'status' => $channel->status,
                'sort_order' => $channel->sort_order,
                'category_ids' => $channel->categories()->pluck('categories.id'),
                'events_count' => $channel->events()->count(),
                'published_events_count' => $channel->events()->published()->count(),
            ],
            'categories' => $this->categoryOptions(),
            'statuses' => Channel::STATUSES,
        ]);
    }

    public function update(Request $request, Channel $channel): RedirectResponse
    {
        $channel->update($this->validated($request, $channel));
        $channel->categories()->sync($request->input('category_ids', []));

        return redirect()->route('admin.channels.index')->with('success', "Channel \"{$channel->name}\" saved.");
    }

    private function categoryOptions()
    {
        return Category::query()->orderBy('name')->get(['id', 'name']);
    }

    private function validated(Request $request, ?Channel $channel = null): array
    {
        // A blank slug is derived from the name, before validation, so the uniqueness rule covers it.
        $request->merge(['slug' => Str::slug($request->input('slug') ?: (string) $request->input('name'))]);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => ['required', 'string', 'max:255', Rule::unique('channels', 'slug')->ignore($channel?->id)],
            'description' => 'nullable|string|max:5000',
            'short_description' => 'nullable|string|max:255',
            'status' => ['required', Rule::in(Channel::STATUSES)],
            'sort_order' => 'nullable|integer|min:0|max:65535',
            'category_ids' => 'array',
            'category_ids.*' => 'integer|exists:categories,id',
        ]);

        unset($data['category_ids']);
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return $data;
    }
}
