<?php

namespace Tests\Feature\Scheduling;

use App\Support\ScheduleMinutes;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * The minutes the Laravel scheduler runs its commands (logs/2026 1122 1626 Plan E Stagger Cron Minutes Off The Half Hour.md):
 * off :00 and :30, which the hourly jobs in api/cron/schedule.php and other sites' jobs use, and different for the two copies.
 */
class ScheduleMinutesTest extends TestCase
{
    private function minutes(string $expression): array
    {
        return array_map('intval', explode(',', explode(' ', $expression)[0]));
    }

    private function event(string $command)
    {
        foreach (app(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, $command)) {
                return $event;
            }
        }
        $this->fail("No scheduled event for {$command}");
    }

    public function test_production_ingests_at_12_and_42_and_every_other_environment_at_17_and_47(): void
    {
        $this->assertSame('12,42 * * * *', ScheduleMinutes::ingestCron('production'));
        foreach (['staging', 'local', 'testing'] as $environment) {
            $this->assertSame('17,47 * * * *', ScheduleMinutes::ingestCron($environment), $environment);
        }
    }

    public function test_the_two_copies_never_run_in_the_same_minute_and_neither_uses_00_or_30(): void
    {
        $production = $this->minutes(ScheduleMinutes::ingestCron('production'));
        $staging = $this->minutes(ScheduleMinutes::ingestCron('staging'));
        $this->assertSame([], array_intersect($production, $staging));
        $this->assertSame([], array_intersect(array_merge($production, $staging), [0, 30]));
    }

    public function test_the_scheduler_uses_these_minutes(): void
    {
        $this->assertSame(ScheduleMinutes::ingestCron(app()->environment()), $this->event('ingest:ics')->expression);
    }

    public function test_the_daily_submissions_email_is_at_0807_pacific(): void
    {
        $event = $this->event('submissions:notify-pending');
        $this->assertSame('7 8 * * *', $event->expression);
        $this->assertSame('America/Los_Angeles', (string) $event->timezone);
    }
}
