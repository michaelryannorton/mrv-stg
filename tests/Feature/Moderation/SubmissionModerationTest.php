<?php

namespace Tests\Feature\Moderation;

use App\Models\Event;
use App\Models\PublicSubmission;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The public "Submit an Event" form's review step: approving creates a published event. */
class SubmissionModerationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['name' => 'Reviewer'])->assignRole('administrator');
    }

    private function submission(string $title = 'Community Picnic', array $extra = []): PublicSubmission
    {
        return PublicSubmission::create([
            'submission_type' => 'event',
            'name' => 'Pat Submitter',
            'email' => 'pat@example.test',
            'source_url' => 'https://example.test/picnic',
            'submitted_data' => [
                'title' => $title,
                'start_at' => '2026-11-14 11:00:00',
                'timezone' => 'America/Los_Angeles',
                'location_name_override' => 'Civic Park',
                'is_free' => true,
                ...$extra,
            ],
            'status' => 'pending',
        ]);
    }

    public function test_approving_creates_a_published_event_with_a_source_and_records_the_reviewer(): void
    {
        $admin = $this->admin();
        $submission = $this->submission();
        $this->actingAs($admin);

        $this->post(route('admin.submissions.bulk'), ['action' => 'approve', 'ids' => [$submission->id]])
            ->assertRedirect()->assertSessionHas('success', '1 submission approved and published.');

        $submission->refresh();
        $event = Event::findOrFail($submission->event_id);
        $this->assertSame('approved', $submission->status);
        $this->assertSame($admin->id, $submission->reviewed_by);
        $this->assertNotNull($submission->reviewed_at);
        $this->assertSame('Community Picnic', $event->title);
        $this->assertSame('published', $event->editorial_status);
        $this->assertSame('scheduled', $event->status);
        $this->assertSame($admin->id, $event->created_by_user_id);
        $this->assertSame('Civic Park', $event->location_name_override);
        $this->assertTrue($event->is_free);
        $this->assertTrue(Event::published()->whereKey($event->id)->exists());
        // 11:00 in Los Angeles in mid-November is 19:00 UTC.
        $this->assertSame('2026-11-14 19:00:00', $event->start_at->utc()->format('Y-m-d H:i:s'));
        $link = $event->eventSources()->with('source')->firstOrFail();
        $this->assertSame('public_submission', $link->source->source_type);
        $this->assertSame('https://example.test/picnic', $link->source_url);
    }

    public function test_rejecting_marks_the_submission_and_creates_no_event(): void
    {
        $submission = $this->submission();
        $this->actingAs($this->admin());

        $this->post(route('admin.submissions.bulk'), ['action' => 'reject', 'ids' => [$submission->id]])
            ->assertSessionHas('success', '1 submission rejected.');

        $this->assertSame('rejected', $submission->fresh()->status);
        $this->assertNull($submission->fresh()->event_id);
        $this->assertSame(0, Event::count());
    }

    public function test_a_bulk_approval_handles_several_submissions_and_reuses_one_source(): void
    {
        $a = $this->submission('First');
        $b = $this->submission('Second');
        $this->actingAs($this->admin());

        $this->post(route('admin.submissions.bulk'), ['action' => 'approve', 'ids' => [$a->id, $b->id]])
            ->assertSessionHas('success', '2 submissions approved and published.');

        $this->assertSame(2, Event::count());
        $this->assertSame(1, Source::where('source_type', 'public_submission')->count());
    }

    public function test_validation_rejects_a_bad_action_and_missing_ids(): void
    {
        $submission = $this->submission();
        $this->actingAs($this->admin());

        $this->post(route('admin.submissions.bulk'), ['action' => 'publish', 'ids' => [$submission->id]])->assertSessionHasErrors('action');
        $this->post(route('admin.submissions.bulk'), ['action' => 'approve', 'ids' => []])->assertSessionHasErrors('ids');
        $this->post(route('admin.submissions.bulk'), ['action' => 'approve', 'ids' => [999999]])->assertSessionHasErrors('ids.0');

        $this->assertSame('pending', $submission->fresh()->status);
    }

    public function test_the_submissions_page_lists_by_status_with_counts(): void
    {
        $this->submission('Pending one');
        $approved = $this->submission('Approved one');
        $approved->update(['status' => 'approved']);
        $this->actingAs($this->admin());

        $this->get(route('admin.submissions.index'))->assertInertia(fn (Assert $page) => $page
            ->where('status', 'pending')
            ->has('submissions', 1)
            ->where('counts', ['pending' => 1, 'approved' => 1, 'rejected' => 0]));
        $this->get(route('admin.submissions.index', ['status' => 'approved']))->assertInertia(fn (Assert $page) => $page->has('submissions', 1));
    }

    public function test_only_administrators_can_review_submissions(): void
    {
        $submission = $this->submission();

        foreach (['editor', 'curator'] as $role) {
            $this->actingAs(User::factory()->create()->assignRole($role));
            $this->post(route('admin.submissions.bulk'), ['action' => 'approve', 'ids' => [$submission->id]])->assertForbidden();
        }

        $this->assertSame('pending', $submission->fresh()->status);
        $this->assertSame(0, Event::count());
    }
}
