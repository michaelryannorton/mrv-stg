<?php

namespace Tests\Feature\Events;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The public "Details" link (slice 1d-i). Ingested URLs come from third-party feeds, so the server
 * decides what is safe to link: only absolute http and https addresses reach the page as details_url.
 */
class EventDetailsLinkTest extends TestCase
{
    use RefreshDatabase;

    private function event(?string $url, string $title = 'Linked event'): Event
    {
        return Event::create([
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::random(4),
            'start_at' => now()->addDay(),
            'timezone' => 'America/Los_Angeles',
            'status' => 'published',
            'editorial_status' => 'published',
            'canonical_url' => $url,
        ]);
    }

    public static function safeUrls(): array
    {
        return [
            'https' => ['https://example.org/events/1', 'https://example.org/events/1'],
            'http' => ['http://example.org/events/1', 'http://example.org/events/1'],
            'uppercase scheme' => ['HTTPS://Example.org/x', 'HTTPS://Example.org/x'],
            'surrounding whitespace is trimmed' => ["  https://example.org/x \n", 'https://example.org/x'],
            'query and fragment kept' => ['https://example.org/e?id=5#top', 'https://example.org/e?id=5#top'],
        ];
    }

    public static function unsafeUrls(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'javascript with leading space' => [' javascript:alert(1)'],
            'mixed case javascript' => ['JaVaScRiPt:alert(1)'],
            'javascript with a host' => ['javascript://example.org/%0Aalert(1)'],
            'data' => ['data:text/html;base64,PHNjcmlwdD4='],
            'data with a host' => ['data://example.org/x'],
            'vbscript' => ['vbscript:msgbox(1)'],
            'file' => ['file:///etc/passwd'],
            'ftp' => ['ftp://example.org/x'],
            'mailto' => ['mailto:someone@example.org'],
            'protocol-relative' => ['//example.org/x'],
            'relative path' => ['/events/1'],
            'no scheme' => ['example.org/events'],
            'scheme without host' => ['https:///path'],
            'scheme and path only' => ['https:example.org/x'],
            'empty' => [''],
            'only whitespace' => ['   '],
        ];
    }

    #[DataProvider('safeUrls')]
    public function test_http_and_https_addresses_become_details_urls(string $stored, string $expected): void
    {
        $this->assertSame($expected, $this->event($stored)->details_url);
    }

    #[DataProvider('unsafeUrls')]
    public function test_anything_else_has_no_details_url(string $stored): void
    {
        $this->assertNull($this->event($stored)->details_url);
    }

    public function test_an_event_without_a_url_has_no_details_url(): void
    {
        $this->assertNull($this->event(null)->details_url);
    }

    public function test_the_public_list_sends_details_url_and_withholds_unsafe_ones(): void
    {
        $this->event('https://example.org/good', 'Good link');
        $this->event('javascript:alert(1)', 'Bad link');

        $this->get(route('events.index'))->assertInertia(fn (Assert $page) => $page
            ->component('events/index')
            ->has('events.data', 2)
            ->where('events.data.0.details_url', 'https://example.org/good')
            ->where('events.data.1.details_url', null));
    }

    public function test_every_calendar_view_still_renders_with_the_shared_card(): void
    {
        $this->event('https://example.org/good');

        foreach (['list', 'day', 'week', 'month'] as $view) {
            $this->get(route('events.index', ['view' => $view, 'date' => now()->addDay()->toDateString()]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('events/index')->where('view', $view));
        }
    }
}
