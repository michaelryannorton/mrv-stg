<?php

namespace Tests\Feature\Invitations;

use App\Models\Category;
use App\Models\Channel;
use App\Models\CuratorAssignment;
use App\Models\CuratorProfile;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * One continuous story across slices 1a to 1c, with nothing faked: the administrator invites a
 * curator; the link is taken from the real rendered email; the curator accepts, logs in, gets
 * nothing until assigned, curates, edits their profile, and is finally removed.
 */
class UserLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'a-long-test-password-1';

    private function lastEmailHtml(): string
    {
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();

        return $messages->last()->getOriginalMessage()->getHtmlBody();
    }

    private function logOut(): void
    {
        auth()->logout();
        $this->flushSession();
    }

    public function test_the_whole_lifecycle_from_invitation_to_removal(): void
    {
        config(['mail.default' => 'array']);

        // The administrator sets up one channel with one published event in its category.
        $admin = User::factory()->create(['name' => 'Owner'])->assignRole('administrator');
        $music = Category::create(['name' => 'Local Music', 'slug' => 'local-music']);
        $food = Category::create(['name' => 'Food', 'slug' => 'food']);
        $this->actingAs($admin);
        $this->post(route('admin.channels.store'), ['name' => 'Live Music', 'status' => 'active', 'category_ids' => [$music->id]])
            ->assertRedirect(route('admin.channels.index'));
        $this->post(route('admin.channels.store'), ['name' => 'Food', 'status' => 'active', 'category_ids' => [$food->id]]);
        $channel = Channel::where('slug', 'live-music')->firstOrFail();
        $foodChannel = Channel::where('slug', 'food')->firstOrFail();
        $event = Event::create(['title' => 'Jazz Night', 'slug' => 'jazz-night', 'start_at' => now()->addDays(4), 'timezone' => 'America/Los_Angeles', 'status' => 'scheduled', 'editorial_status' => 'published']);
        $event->categories()->attach($music->id);

        // 1. Invitation: the account is pending and an email goes out.
        $this->post(route('admin.users.store'), ['name' => 'Casey Curator', 'email' => 'Casey@Example.Test', 'role' => 'curator'])
            ->assertRedirect(route('admin.users.index'));
        $casey = User::where('email', 'casey@example.test')->firstOrFail();
        $this->assertTrue($casey->isPending());
        $this->get(route('admin.users.edit', $casey))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('user.status', 'invited')->where('user.role', 'curator')->has('assignments', 0));

        // 2. The link comes out of the real email and works for a signed-out visitor.
        preg_match('~/invitation/([a-f0-9]+)\?email=([^"\s&<]+)~', $this->lastEmailHtml(), $link);
        $this->assertNotEmpty($link, 'no invitation link in the email');
        $this->logOut();
        $this->get('/invitation/'.$link[1].'?email='.$link[2])->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('auth/accept-invitation')->where('valid', true)->where('name', 'Casey Curator'));

        // 3. Setting a password completes the invitation; the link is then dead.
        $this->post(route('invitation.store'), ['token' => $link[1], 'email' => urldecode($link[2]), 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertRedirect(route('login'));
        $this->assertFalse($casey->fresh()->isPending());
        $this->post(route('invitation.store'), ['token' => $link[1], 'email' => urldecode($link[2]), 'password' => 'another-password-22', 'password_confirmation' => 'another-password-22'])
            ->assertSessionHasErrors('email');

        // 4. The curator logs in and lands in the workspace, which is empty until assigned.
        $this->post(route('login'), ['email' => 'casey@example.test', 'password' => self::PASSWORD])->assertRedirect(route('curate.index', absolute: false));
        $this->get(route('curate.index'))->assertInertia(fn (Assert $page) => $page->has('channels', 0)->where('viewer_is_administrator', false));
        $this->get(route('curate.channels.show', $channel->slug))->assertNotFound();
        $this->get(route('admin.events.index'))->assertForbidden();
        $this->get(route('admin.users.index'))->assertForbidden();
        $this->post(route('admin.users.store'), ['name' => 'X', 'email' => 'x@example.test', 'role' => 'curator'])->assertForbidden();

        // 5. The administrator assigns the channel; the curator now sees exactly that one.
        $this->logOut();
        $this->actingAs($admin)->post(route('admin.users.assignments.store', $casey), ['channel_id' => $channel->id, 'role' => 'lead'])->assertRedirect();
        $this->logOut();
        $this->post(route('login'), ['email' => 'casey@example.test', 'password' => self::PASSWORD]);
        $this->get(route('curate.index'))->assertInertia(fn (Assert $page) => $page->has('channels', 1)->where('channels.0.slug', 'live-music')->where('channels.0.assignment_role', 'lead'));
        $this->get(route('curate.channels.show', $channel->slug))->assertOk()->assertInertia(fn (Assert $page) => $page->has('events.data', 1)->where('events.data.0.title', 'Jazz Night'));
        $this->get(route('curate.channels.show', $foodChannel->slug))->assertNotFound();

        // 6. The curator curates, but cannot touch the event itself.
        $this->post(route('curate.channels.act', [$channel->slug, $event->id, 'feature']))->assertRedirect();
        $this->assertSame('featured', DB::table('event_channels')->where('event_id', $event->id)->value('relationship_type'));
        $this->post(route('curate.channels.act', [$foodChannel->slug, $event->id, 'feature']))->assertNotFound();
        $this->post(route('admin.events.bulk'), ['action' => 'reject', 'ids' => [$event->id]])->assertForbidden();
        $this->assertSame('published', $event->fresh()->editorial_status);

        // 7. The curator edits only their own public profile.
        $this->put(route('curate.profile.update'), ['display_name' => 'Casey C.', 'blurb' => 'I cover live music.', 'is_public' => true])->assertRedirect();
        $this->assertSame('Casey C.', $casey->fresh()->curatorProfile->display_name);
        $this->assertSame(1, CuratorProfile::count());

        // 8. The administrator removes the account; everything of the curator's goes except the pick.
        DB::table('sessions')->insert(['id' => 'lifecycle', 'user_id' => $casey->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        $this->logOut();
        $this->actingAs($admin)->post(route('admin.users.remove', $casey))->assertRedirect(route('admin.users.index'));
        $this->assertNull(User::find($casey->id));
        $this->assertSame(0, CuratorAssignment::count());
        $this->assertSame(0, CuratorProfile::count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $casey->id)->count());
        $this->assertSame('featured', DB::table('event_channels')->where('event_id', $event->id)->value('relationship_type'));
        $this->assertNull(DB::table('event_channels')->where('event_id', $event->id)->value('added_by_user_id'));
        $this->logOut();
        $this->post(route('login'), ['email' => 'casey@example.test', 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
