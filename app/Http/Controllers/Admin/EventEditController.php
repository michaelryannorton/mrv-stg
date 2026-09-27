<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Audience;
use App\Models\Category;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EventEditController extends Controller
{
    public function edit(Event $event): Response
    {
        $event->load(['venue', 'organizer', 'categories', 'tags', 'audiences', 'eventSources.source']);

        return Inertia::render('admin/events/edit', [
            'event' => $event,
            'venues' => Venue::orderBy('name')->get(['id', 'name', 'city']),
            'organizations' => Organization::orderBy('name')->get(['id', 'name']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'tags' => Tag::orderBy('name')->get(['id', 'name']),
            'audiences' => Audience::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Event $event): RedirectResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:500',
            'short_description' => 'nullable|string',
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
            'ticket_url' => 'nullable|url|max:2048',
            'price_min' => 'nullable|numeric|min:0',
            'price_max' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'is_free' => 'boolean',
            'age_restriction' => 'nullable|string|max:255',
            'accessibility_notes' => 'nullable|string',
            'category_ids' => 'array',
            'category_ids.*' => 'integer|exists:categories,id',
            'tag_ids' => 'array',
            'tag_ids.*' => 'integer|exists:tags,id',
            'audience_ids' => 'array',
            'audience_ids.*' => 'integer|exists:audiences,id',
        ]);

        $categoryIds = $data['category_ids'] ?? [];
        $tagIds = $data['tag_ids'] ?? [];
        $audienceIds = $data['audience_ids'] ?? [];
        unset($data['category_ids'], $data['tag_ids'], $data['audience_ids']);

        // The datetime-local inputs submit bare wall-clock strings with no offset — attach the
        // form's own (possibly just-edited) timezone before comparing or saving, the same
        // requirement Event::setStartAtAttribute's own docblock calls out for any bare-string caller.
        $data['start_at'] = Carbon::parse($data['start_at'], $data['timezone']);
        $data['end_at'] = isset($data['end_at']) ? Carbon::parse($data['end_at'], $data['timezone']) : null;

        // Any INGESTED_FIELDS column whose submitted value differs from what's currently stored is
        // a manual edit that the next re-ingestion must not silently revert — lock it via
        // overridden_fields before saving. Fields outside that set (is_free, price, categories,
        // tags, audiences, venue, ...) are never touched by ingestion, so nothing to protect there.
        $changedIngestedFields = array_values(array_filter(
            Event::INGESTED_FIELDS,
            fn (string $field) => $this->valueChanged($event->getAttribute($field), $data[$field] ?? null),
        ));

        if ($changedIngestedFields !== []) {
            $event->protectFields($changedIngestedFields);
        }

        $event->update($data);
        $event->categories()->sync($categoryIds);
        $event->tags()->sync($tagIds);
        $event->audiences()->sync($audienceIds);

        return redirect()->route('admin.events.index')->with('success', "\"{$event->title}\" updated.");
    }

    /**
     * Compares a currently-stored attribute (possibly a Carbon instance, via Event's casts) against
     * the freshly submitted, still-raw form value for that same field.
     */
    private function valueChanged(mixed $current, mixed $incoming): bool
    {
        if ($current instanceof \DateTimeInterface) {
            $incoming = $incoming === null ? null : Carbon::parse($incoming);

            return $incoming === null
                ? $current !== null
                : ! $current->equalTo($incoming);
        }

        if (is_bool($current)) {
            return $current !== (bool) $incoming;
        }

        return $current !== ($incoming === '' ? null : $incoming);
    }
}
