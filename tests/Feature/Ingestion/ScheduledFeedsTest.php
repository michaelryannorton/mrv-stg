<?php

namespace Tests\Feature\Ingestion;

use App\Models\Source;
use App\Support\ScheduleMinutes;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Only the feeds somebody marked are collected on a schedule; an active source alone is not one. */
class ScheduledFeedsTest extends TestCase
{
    use RefreshDatabase;

    private function source(string $name, string $collector, ?array $config = null, array $more = []): Source
    {
        return Source::create([
            'name' => $name, 'source_type' => 'api', 'collector_type' => $collector, 'access_scope' => 'public',
            'feed_url' => 'https://example.test/'.str_replace(' ', '-', $name), 'base_url' => 'https://example.test/', 'active' => true,
            'collector_config' => $config, ...$more,
        ]);
    }

    public function test_the_command_collects_only_marked_feeds(): void
    {
        $this->source('marked', 'tribe_events', ['scheduled' => true]);
        $this->source('unmarked', 'tribe_events');
        $this->source('off', 'tribe_events', ['scheduled' => true], ['active' => false]);
        $this->source('calendar', 'ics');
        Http::fake(['*' => Http::response(['total_pages' => 1, 'events' => []])]);

        $this->artisan('ingest:feeds')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://example.test/marked'));
    }

    public function test_the_command_says_so_when_nothing_is_marked(): void
    {
        $this->source('unmarked', 'tribe_events');
        Http::fake();

        $this->artisan('ingest:feeds')->expectsOutput('No feeds are marked for scheduled collection.')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_scheduled_sources_and_how_often(): void
    {
        $marked = $this->source('marked', 'growthzone', ['scheduled' => true]);
        $unmarked = $this->source('unmarked', 'tribe_events');
        $calendar = $this->source('calendar', 'ics');

        $this->assertSame([$marked->id, $calendar->id], Source::scheduled()->orderBy('id')->pluck('id')->all());
        $this->assertSame('every 6 hours', $marked->schedule());
        $this->assertSame('every 30 minutes', $calendar->schedule());
        $this->assertNull($unmarked->schedule());
    }

    public function test_the_scheduler_runs_the_feeds_four_times_a_day_off_the_other_minutes(): void
    {
        $this->assertSame('27 1,7,13,19 * * *', ScheduleMinutes::feedsCron('production'));
        $this->assertSame('52 1,7,13,19 * * *', ScheduleMinutes::feedsCron('staging'));

        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'ingest:feeds'));
        $this->assertSame(ScheduleMinutes::feedsCron(app()->environment()), $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_the_four_approved_feeds_are_set_up_and_nothing_else_changes(): void
    {
        foreach (['SRC-0010', 'SRC-0017', 'SRC-0091', 'SRC-0133', 'SRC-0009'] as $legacy) {
            Source::create([
                'legacy_source_id' => $legacy, 'name' => $legacy, 'source_type' => 'other', 'collector_type' => 'manual',
                'base_url' => 'https://example.test/'.$legacy, 'access_scope' => 'public', 'active' => false,
            ]);
        }

        $this->artisan('activate:approved-feeds')->expectsOutput('4 of 4 feeds set up.')->assertSuccessful();
        $this->artisan('activate:approved-feeds')->assertSuccessful();

        $this->assertSame(['SRC-0010', 'SRC-0017', 'SRC-0091', 'SRC-0133'], Source::scheduled()->orderBy('legacy_source_id')->pluck('legacy_source_id')->all());
        $this->assertSame('growthzone', Source::where('legacy_source_id', 'SRC-0017')->value('collector_type'));
        $this->assertFalse(Source::where('legacy_source_id', 'SRC-0009')->first()->active);
    }

    public function test_a_source_without_a_web_page_is_not_set_up(): void
    {
        Source::create(['legacy_source_id' => 'SRC-0017', 'name' => 'Chamber', 'source_type' => 'other', 'collector_type' => 'manual', 'access_scope' => 'public']);

        $this->artisan('activate:approved-feeds')->assertFailed();

        $this->assertSame(0, Source::scheduled()->count());
    }
}
