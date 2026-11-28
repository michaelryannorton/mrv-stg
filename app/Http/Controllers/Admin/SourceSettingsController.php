<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Source;
use App\Support\ExternalUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administrator-only settings of the active feeds: for now the web page of each, which public event
 * cards link to ("Source calendar") when an event has no address of its own. See
 * PublicEventCard::link().
 */
class SourceSettingsController extends Controller
{
    public function index(): Response
    {
        $feeds = Source::query()
            ->where('active', true)
            ->where('collector_type', '!=', 'manual')
            ->withCount(['eventSources as upcoming_count' => fn ($q) => $q->whereHas('event', fn ($q) => $q->upcoming())])
            ->orderBy('name')
            ->get()
            ->map(fn (Source $source) => [
                'id' => $source->id,
                'name' => $source->name,
                'collector_type' => $source->collector_type,
                // The host only: a feed address can carry a private token.
                'feed_host' => parse_url((string) $source->feed_url, PHP_URL_HOST) ?: null,
                'web_page' => $source->base_url,
                'web_page_works_as_link' => $source->webPage() !== null,
                'upcoming_count' => $source->upcoming_count,
            ]);

        return Inertia::render('admin/sources/index', ['feeds' => $feeds]);
    }

    public function update(Request $request, Source $source): RedirectResponse
    {
        abort_unless($source->active && $source->isFeed(), 404);

        $data = $request->validate(
            [
                'web_page' => [
                    'required', 'string', 'max:2048',
                    fn (string $attribute, mixed $value, \Closure $fail) => ExternalUrl::web((string) $value) === null
                        ? $fail('Enter a full address that starts with https:// or http://.')
                        : null,
                ],
            ],
            ['web_page.required' => 'Each active feed needs a web page.'],
        );

        $source->update(['base_url' => ExternalUrl::web($data['web_page'])]);

        return back()->with('success', "Web page of \"{$source->name}\" saved.");
    }
}
