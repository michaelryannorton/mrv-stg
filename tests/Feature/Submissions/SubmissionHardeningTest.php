<?php

namespace Tests\Feature\Submissions;

use App\Models\PublicSubmission;
use App\Models\User;
use App\Notifications\PendingSubmissionsDigest;
use App\Support\SubmissionFormGuard;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Fixes F1 to F7 and the spam checks (D1) for the public event form, and the daily email (D4).
 * Findings and decisions: logs/2026 1120 1903 Cloudflare Range Check And Submit Event Review.md.
 */
class SubmissionHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A valid submission, with $overrides applied; the form was opened a minute ago unless said otherwise. */
    private function submit(array $overrides = []): TestResponse
    {
        return $this->post(route('submit.store'), [
            'title' => 'Block party',
            'start_at' => now()->addMonth()->format('Y-m-d\TH:i'),
            'timezone' => 'America/Los_Angeles',
            'form_token' => SubmissionFormGuard::issue(now()->subMinute()),
            ...$overrides,
        ]);
    }

    // F1 to F4 and F7: what the form accepts

    public function test_a_valid_submission_is_stored(): void
    {
        $this->submit()->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(1, PublicSubmission::count());
    }

    public function test_an_unknown_time_zone_is_refused(): void
    {
        $this->submit(['timezone' => 'Not/AZone'])->assertSessionHasErrors('timezone');
        $this->assertSame(0, PublicSubmission::count());
    }

    public function test_long_text_is_refused(): void
    {
        $this->submit(['description' => str_repeat('a', 10001)])->assertSessionHasErrors('description');
        $this->submit(['address_override' => str_repeat('a', 501)])->assertSessionHasErrors('address_override');
        $this->assertSame(0, PublicSubmission::count());

        $this->submit(['description' => str_repeat('a', 10000), 'address_override' => str_repeat('a', 500)])->assertSessionHasNoErrors();
        $this->assertSame(1, PublicSubmission::count());
    }

    public function test_the_event_link_must_be_http_or_https(): void
    {
        foreach (['ftp://example.org/e', 'javascript:alert(1)', 'data:text/html,hi'] as $link) {
            $this->submit(['canonical_url' => $link])->assertSessionHasErrors('canonical_url');
        }
        $this->assertSame(0, PublicSubmission::count());

        $this->submit(['canonical_url' => 'https://example.org/e'])->assertSessionHasNoErrors();
        $this->assertSame(1, PublicSubmission::count());
    }

    public function test_the_start_must_be_from_a_year_back_to_three_years_ahead(): void
    {
        Carbon::setTestNow('2026-11-20 12:00:00');

        $this->submit(['start_at' => '0001-01-01T10:00'])->assertSessionHasErrors('start_at');
        $this->submit(['start_at' => '2025-11-19T10:00'])->assertSessionHasErrors('start_at');
        $this->submit(['start_at' => '2029-11-21T10:00'])->assertSessionHasErrors('start_at');
        $this->assertSame(0, PublicSubmission::count());

        $this->submit(['start_at' => '2025-11-20T10:00'])->assertSessionHasNoErrors();
        $this->submit(['start_at' => '2029-11-20T10:00'])->assertSessionHasNoErrors();
        $this->assertSame(2, PublicSubmission::count());
    }

    public function test_the_end_follows_the_start_and_stays_in_the_same_window(): void
    {
        Carbon::setTestNow('2026-11-20 12:00:00');

        $this->submit(['start_at' => '2026-12-01T10:00', 'end_at' => '2026-12-01T09:00'])->assertSessionHasErrors('end_at');
        $this->submit(['start_at' => '2026-12-01T10:00', 'end_at' => '2030-01-01T09:00'])->assertSessionHasErrors('end_at');
        $this->assertSame(0, PublicSubmission::count());

        $this->submit(['start_at' => '2026-12-01T10:00', 'end_at' => '2026-12-01T12:00'])->assertSessionHasNoErrors();
    }

    // F6: link lookups

    private function lookup(int $n): TestResponse
    {
        return $this->get(route('submit.create', ['url' => 'https://example.org/e'.$n]));
    }

    public function test_the_thirty_first_lookup_in_an_hour_is_refused_before_any_fetch(): void
    {
        Http::fake(['*' => Http::response('<html><title>x</title></html>', 200, ['Content-Type' => 'text/html'])]);
        Carbon::setTestNow('2026-11-20 09:00:00');

        for ($i = 0; $i < 30; $i++) {
            if ($i > 0 && $i % 6 === 0) {
                Carbon::setTestNow(now()->addSeconds(61));
            }
            $this->lookup($i)->assertOk();
        }
        Http::assertSentCount(30);

        Carbon::setTestNow(now()->addSeconds(61));
        $this->lookup(31)->assertRedirect(route('submit.create'))->assertSessionHas('error');
        Http::assertSentCount(30);

        Carbon::setTestNow(now()->addHour());
        $this->lookup(32)->assertOk();
    }

    public function test_the_hundred_and_first_lookup_in_a_day_is_refused_even_when_the_hourly_allowance_resets(): void
    {
        Http::fake(['*' => Http::response('<html><title>x</title></html>', 200, ['Content-Type' => 'text/html'])]);
        $sent = 0;

        foreach ([30, 30, 30, 10] as $hour => $lookups) {
            Carbon::setTestNow(Carbon::parse('2026-11-20 06:00:00')->addHours($hour)->addMinute());
            for ($i = 0; $i < $lookups; $i++) {
                if ($i > 0 && $i % 6 === 0) {
                    Carbon::setTestNow(now()->addSeconds(61));
                }
                $this->lookup($sent++)->assertOk();
            }
        }
        $this->assertSame(100, $sent);
        Http::assertSentCount(100);

        Carbon::setTestNow(Carbon::parse('2026-11-20 10:30:00'));
        $this->lookup(101)->assertRedirect(route('submit.create'))
            ->assertSessionHas('error', fn (string $m) => str_contains($m, "today's link lookups"));
        Http::assertSentCount(100);
    }

    // D1: the hidden field and the minimum time

    public function test_the_form_page_carries_a_token_that_passes_after_the_minimum_time(): void
    {
        Carbon::setTestNow('2026-11-20 12:00:00');
        $token = null;
        $this->get(route('submit.create'))->assertInertia(function (Assert $page) use (&$token) {
            $page->component('submit/create')->has('formToken');
            $token = $page->toArray()['props']['formToken'];
        });

        $this->submit(['form_token' => $token])->assertSessionHas('error');
        $this->assertSame(0, PublicSubmission::count());

        Carbon::setTestNow(now()->addSeconds(SubmissionFormGuard::MIN_SECONDS));
        $this->submit(['form_token' => $token])->assertSessionHas('success');
        $this->assertSame(1, PublicSubmission::count());
    }

    public function test_a_filled_hidden_field_stores_nothing_and_looks_like_a_success(): void
    {
        $this->submit(['homepage_url' => 'https://spam.example'])->assertRedirect(route('submit.create'))
            ->assertSessionHas('success')->assertSessionMissing('error');
        $this->assertSame(0, PublicSubmission::count());
    }

    public function test_a_missing_or_altered_token_asks_for_a_reload_and_stores_nothing(): void
    {
        $this->submit(['form_token' => null])->assertSessionHas('error', fn (string $m) => str_contains($m, 'reload the page'));
        $this->submit(['form_token' => 'not-a-token'])->assertSessionHas('error', fn (string $m) => str_contains($m, 'reload the page'));
        $this->submit(['form_token' => SubmissionFormGuard::issue(now()->addMinute())])->assertSessionHas('error');
        $this->assertSame(0, PublicSubmission::count());
    }

    public function test_the_checks_run_before_validation_so_a_script_learns_nothing_about_the_fields(): void
    {
        $this->submit(['title' => '', 'form_token' => null])->assertSessionHasNoErrors()->assertSessionHas('error');
        $this->submit(['title' => '', 'homepage_url' => 'x'])->assertSessionHasNoErrors();
    }

    // D4: the daily email

    private function pending(string $title = 'Waiting event', string $status = 'pending', ?Carbon $createdAt = null): PublicSubmission
    {
        $submission = PublicSubmission::create([
            'submission_type' => 'event',
            'submitted_data' => ['title' => $title, 'start_at' => '2026-12-01 10:00:00', 'timezone' => 'America/Los_Angeles'],
            'status' => $status,
        ]);
        if ($createdAt) {
            $submission->forceFill(['created_at' => $createdAt])->save();
        }

        return $submission;
    }

    public function test_nothing_is_sent_when_no_submission_is_pending(): void
    {
        Notification::fake();
        User::factory()->create()->assignRole('administrator');
        $this->pending('Done', 'approved');
        $this->pending('Junk', 'spam');

        $this->artisan('submissions:notify-pending')->expectsOutputToContain('no email sent')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_every_administrator_and_nobody_else_gets_the_email_when_submissions_wait(): void
    {
        Notification::fake();
        $admin = User::factory()->create()->assignRole('administrator');
        $second = User::factory()->create()->assignRole('administrator');
        $editor = User::factory()->create()->assignRole('editor');
        $this->pending('One', createdAt: now()->subDays(3));
        $this->pending('Two');

        $this->artisan('submissions:notify-pending')->expectsOutputToContain('2 pending; email sent to 2 of 2')->assertSuccessful();

        Notification::assertSentTo([$admin, $second], PendingSubmissionsDigest::class, fn ($n) => $n->count === 2);
        Notification::assertNotSentTo($editor, PendingSubmissionsDigest::class);
        Notification::assertCount(2);
    }

    public function test_the_email_gives_the_count_the_oldest_wait_and_a_link_but_no_submitted_text(): void
    {
        $admin = User::factory()->create(['name' => 'Pat'])->assignRole('administrator');
        $this->pending('Secret title from a visitor', createdAt: now()->subDays(3));

        $mail = (new PendingSubmissionsDigest(1, now()->subDays(3)))->toMail($admin);
        $text = implode("\n", [$mail->subject, ...$mail->introLines, $mail->actionUrl]);

        $this->assertSame('1 event submission waiting for review', $mail->subject);
        $this->assertStringContainsString('1 event submission is waiting for review. The oldest has been waiting 3 days.', $text);
        $this->assertStringContainsString(route('admin.submissions.index'), $text);
        $this->assertStringNotContainsString('Secret title', $text);
    }

    public function test_pending_without_an_administrator_is_reported_as_a_failure(): void
    {
        $this->pending();

        $this->artisan('submissions:notify-pending')->expectsOutputToContain('no administrator to email')->assertFailed();
    }

    public function test_the_command_is_scheduled_daily_at_eight_pacific(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command, 'submissions:notify-pending'));

        $this->assertCount(1, $events);
        $this->assertSame('0 8 * * *', $events->first()->expression);
        $this->assertSame('America/Los_Angeles', (string) $events->first()->timezone);
        $this->assertTrue($events->first()->withoutOverlapping);
    }
}
