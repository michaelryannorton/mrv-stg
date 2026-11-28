<?php

namespace Tests\Feature\Moderation;

use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** /admin/sources: the web page of each active feed (who may use the route is covered by AdminAccessTest). */
class FeedSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create()->assignRole('administrator'));
    }

    private function source(array $overrides = []): Source
    {
        return Source::create(array_merge([
            'name' => 'Startup Calendar', 'source_type' => 'ics', 'collector_type' => 'ics', 'active' => true,
            'feed_url' => 'https://calendar.example/private-TOKEN/basic.ics',
        ], $overrides));
    }

    public function test_it_lists_active_feeds_only_and_sends_the_feed_host_not_its_address(): void
    {
        $this->source();
        $this->source(['name' => 'Directory entry', 'collector_type' => 'manual']);
        $this->source(['name' => 'Switched off', 'active' => false]);

        $response = $this->get(route('admin.sources.index'));
        $response->assertInertia(fn (Assert $page) => $page
            ->component('admin/sources/index')
            ->has('feeds', 1)
            ->where('feeds.0.name', 'Startup Calendar')
            ->where('feeds.0.feed_host', 'calendar.example')
            ->where('feeds.0.web_page_works_as_link', false));
        $this->assertStringNotContainsString('private-TOKEN', json_encode($response->viewData('page')));
    }

    public function test_a_web_page_is_saved(): void
    {
        $source = $this->source();

        $this->put(route('admin.sources.update', $source), ['web_page' => ' https://startupmojave.org/calendar '])
            ->assertSessionHasNoErrors();

        $this->assertSame('https://startupmojave.org/calendar', $source->fresh()->base_url);
    }

    public static function refused(): array
    {
        return [
            'blank' => [''], 'spaces' => ['   '], 'no scheme' => ['startupmojave.org/calendar'], 'script' => ['javascript:alert(1)'],
            'mail' => ['mailto:a@example.org'], 'too long' => ['https://example.org/'.str_repeat('a', 2100)],
        ];
    }

    #[DataProvider('refused')]
    public function test_it_is_not_saved_blank_or_unsafe(string $value): void
    {
        $source = $this->source(['base_url' => 'https://example.org/before']);

        $this->put(route('admin.sources.update', $source), ['web_page' => $value])->assertSessionHasErrors('web_page');

        $this->assertSame('https://example.org/before', $source->fresh()->base_url);
    }

    public function test_only_an_active_feed_can_be_changed(): void
    {
        foreach ([['collector_type' => 'manual'], ['active' => false]] as $overrides) {
            $source = $this->source($overrides);
            $this->put(route('admin.sources.update', $source), ['web_page' => 'https://example.org'])->assertNotFound();
            $this->assertNull($source->fresh()->base_url);
        }
    }

    public function test_the_activation_command_skips_a_source_without_a_web_page(): void
    {
        $without = $this->source(['legacy_source_id' => 'SRC-0002', 'collector_type' => 'manual', 'active' => false]);
        $with = $this->source(['legacy_source_id' => 'SRC-0008', 'collector_type' => 'manual', 'active' => false, 'base_url' => 'https://parks.example']);

        $this->artisan('activate:confirmed-tribe-sources')->expectsOutputToContain('SRC-0002: no web page')->assertSuccessful();

        $this->assertFalse($without->fresh()->active);
        $this->assertTrue($with->fresh()->active);
    }
}
