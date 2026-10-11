<?php

namespace Tests\Feature\Submissions;

use App\Models\PublicSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * /submit-event is open to anonymous visitors. Each IP address gets 10 submissions an hour and 30 a
 * day, and 6 link lookups a minute (a lookup makes the server fetch a third-party page). Opening the
 * empty form is never limited.
 */
class SubmissionRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const OTHER_IP = '203.0.113.77';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function submit(array $server = []): TestResponse
    {
        return $this->withServerVariables($server)->post(route('submit.store'), [
            'title' => 'Block party',
            'start_at' => '2026-11-14 11:00:00',
            'timezone' => 'America/Los_Angeles',
        ]);
    }

    public function test_the_eleventh_submission_in_an_hour_is_refused_and_stores_nothing(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->submit()->assertRedirect(route('submit.create'));
        }
        $this->assertSame(10, PublicSubmission::count());

        $this->submit()->assertRedirect(route('submit.create'))->assertSessionHas('error');
        $this->assertSame(10, PublicSubmission::count());
    }

    public function test_the_refusal_is_a_readable_message_on_the_form_not_an_error_page(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->submit();
        }

        $this->followingRedirects()->submit()->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('submit/create')
            ->where('flash.error', 'You have submitted several events recently. Please try again in an hour.'));
    }

    public function test_another_address_is_not_affected(): void
    {
        for ($i = 0; $i < 11; $i++) {
            $this->submit();
        }

        $this->submit(['REMOTE_ADDR' => self::OTHER_IP])->assertSessionMissing('error');
        $this->assertSame(11, PublicSubmission::count()); // 10 from the first address, 1 from the other
    }

    public function test_the_hourly_allowance_returns_after_an_hour(): void
    {
        Carbon::setTestNow('2026-10-11 09:00:00');
        for ($i = 0; $i < 11; $i++) {
            $this->submit();
        }
        $this->assertSame(10, PublicSubmission::count());

        Carbon::setTestNow('2026-10-11 10:01:00');
        $this->submit()->assertSessionMissing('error');
        $this->assertSame(11, PublicSubmission::count());
    }

    public function test_the_daily_cap_holds_even_when_the_hourly_allowance_resets(): void
    {
        foreach (['09:00', '10:05', '11:10'] as $time) {
            Carbon::setTestNow("2026-10-11 {$time}:00");
            for ($i = 0; $i < 10; $i++) {
                $this->submit()->assertSessionMissing('error');
            }
        }
        $this->assertSame(30, PublicSubmission::count());

        Carbon::setTestNow('2026-10-11 12:15:00');
        $this->submit()->assertSessionHas('error', 'You have reached the daily limit for submissions. Please try again tomorrow.');
        $this->assertSame(30, PublicSubmission::count());
    }

    public function test_the_seventh_link_lookup_in_a_minute_is_refused_before_any_fetch(): void
    {
        Http::fake(['*' => Http::response('<html><title>x</title></html>', 200, ['Content-Type' => 'text/html'])]);

        for ($i = 0; $i < 6; $i++) {
            $this->get(route('submit.create', ['url' => 'https://example.org/e'.$i]))->assertOk();
        }
        Http::assertSentCount(6);

        $this->get(route('submit.create', ['url' => 'https://example.org/e7']))
            ->assertRedirect(route('submit.create'))
            ->assertSessionHas('error', 'Too many link lookups. Wait a minute, or fill in the form by hand.');
        Http::assertSentCount(6);

        Carbon::setTestNow(now()->addMinutes(2));
        $this->get(route('submit.create', ['url' => 'https://example.org/e8']))->assertOk();
    }

    public function test_opening_the_empty_form_is_never_limited(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->get(route('submit.create'))->assertOk();
        }
    }

    public function test_lookups_and_submissions_have_separate_allowances(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        for ($i = 0; $i < 7; $i++) {
            $this->get(route('submit.create', ['url' => 'https://example.org/e'.$i]));
        }

        $this->submit()->assertSessionMissing('error');
        $this->assertSame(1, PublicSubmission::count());
    }
}
