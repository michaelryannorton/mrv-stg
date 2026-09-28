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
        $event->load(['venue', 'organizer', 'categories', 'tags', 'audiences', 'eventSources.source', 'eventSources.sourceRecord']);

        return Inertia::render('admin/events/edit', [
            'event' => $event,
            'sourceValues' => $this->currentSourceValues($event),
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
            fn (string $field) => Event::valuesDiffer($event->getAttribute($field), $data[$field] ?? null),
        ));

        if ($changedIngestedFields !== []) {
            $event->protectFields($changedIngestedFields);
        }

        // A full-form save is Michael reviewing the event, whether or not anything he touched was
        // actually stale — any previously-flagged source/edit conflict is resolved by this save.
        // (protectFields() above already marked overridden_fields dirty; save() below persists it
        // regardless of what's in $data.)
        $data['stale_fields'] = null;

        $event->update($data);
        $event->categories()->sync($categoryIds);
        $event->tags()->sync($tagIds);
        $event->audiences()->sync($audienceIds);

        return redirect()->route('admin.events.index')->with('success', "\"{$event->title}\" updated.");
    }

    /**
     * Adopts the source's current value for a single locked field — used from the editor's "sync to
     * source" action once a curator has looked at a flagged field and decided to accept the source's
     * newer value rather than keep their own edit. Lifts the lock on that one field only; every other
     * locked field (and its staleness, if any) is untouched.
     */
    public function syncField(Request $request, Event $event): RedirectResponse
    {
        $data = $request->validate([
            'field' => 'required|string|in:'.implode(',', Event::INGESTED_FIELDS),
        ]);
        $field = $data['field'];

        $event->load(['eventSources.sourceRecord', 'eventSources.source']);
        $sourceValues = $this->currentSourceValues($event);

        if (! array_key_exists($field, $sourceValues)) {
            return back()->with('success', 'No current source value to sync — nothing changed.');
        }

        $event->setAttribute($field, $sourceValues[$field]);
        $event->unlockField($field);
        $event->save();

        return redirect()->route('admin.events.edit', $event)->with('success', "\"{$field}\" synced to the source's current value.");
    }

    /**
     * The freshest value the source has reported for each INGESTED_FIELDS column, read from the
     * most recent SourceRecord attached to this event's primary link (IngestIcsSources repoints
     * event_sources.source_record_id at the latest record on every sync, so this is always current
     * as of the last ingestion run, not a live fetch). organizer_id is sourced from the linked
     * Source itself, not the feed payload, since ingestion never reads it from raw_payload either.
     */
    private function currentSourceValues(Event $event): array
    {
        $link = $event->eventSources->first();
        $payload = $link?->sourceRecord?->raw_payload;

        if ($payload === null) {
            return [];
        }

        $values = [];

        foreach (Event::SOURCE_PAYLOAD_KEYS as $field => $payloadKey) {
            if (array_key_exists($payloadKey, $payload)) {
                $values[$field] = $payload[$payloadKey];
            }
        }

        if ($link?->source?->organization_id !== null) {
            $values['organizer_id'] = $link->source->organization_id;
        }

        return $values;
    }
}
