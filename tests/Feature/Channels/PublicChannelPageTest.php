<?php

namespace Tests\Feature\Channels;

use App\Models\Channel;
use App\Models\CuratorAssignment;
use App\Models\CuratorProfile;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Curation\CurationTestCase;

/**
 * The public channel pages (Phase 2 slice 1d-ii): visibility, what is listed, who is named, and
 * what is never sent to the browser.
 */
class PublicChannelPageTest extends CurationTestCase
{
    private function feature(Channel $channel, Event $event): void
    {
        DB::table('event_channels')->insert(['channel_id' => $channel->id, 'event_id' => $event->id, 'relationship_type' => 'featured', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function exclude(Channel $channel, Event $event): void
    {
        DB::table('event_channels')->insert(['channel_id' => $channel->id, 'event_id' => $event->id, 'relationship_type' => 'excluded', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function profile(User $user, array $attributes = []): CuratorProfile
    {
        return CuratorProfile::create(['user_id' => $user->id, 'display_name' => 'Pat Public', 'blurb' => 'I like events.', 'is_public' => true, ...$attributes]);
    }

    private function page(Channel $channel)
    {
        return $this->get(route('channels.show', $channel->slug));
    }

    // Visibility

    public function test_guests_see_an_active_channel(): void
    {
        $channel = $this->channel('Business', status: 'active');

        $this->page($channel)->assertOk()->assertInertia(fn (Assert $page) => $page->component('channels/show')->where('channel.name', 'Business')->where('preview', null));
    }

    public function test_every_other_state_is_a_404_for_guests_curators_and_editors(): void
    {
        $unassigned = $this->curator('curator');
        $editor = $this->curator('editor');

        foreach (['draft', 'inactive', 'archived'] as $status) {
            $channel = $this->channel(ucfirst($status).' channel', status: $status);
            // Assigned to the channel, but the public page is not their workspace: that is /curate.
            $assigned = $this->curator('curator', $channel);

            $this->page($channel)->assertNotFound();

            foreach (['unassigned curator' => $unassigned, 'editor' => $editor, 'assigned curator' => $assigned] as $user) {
                $this->actingAs($user)->get(route('channels.show', $channel->slug))->assertNotFound();
            }

            $this->app['auth']->forgetGuards();
        }

        $this->get('/channels/no-such-channel')->assertNotFound();
    }

    public function test_a_soft_deleted_channel_is_a_404_even_for_an_administrator(): void
    {
        $channel = $this->channel('Gone');
        $channel->delete();

        $this->actingAs($this->admin());
        $this->page($channel)->assertNotFound();
    }

    public function test_an_administrator_previews_a_non_active_channel_with_its_status(): void
    {
        $this->actingAs($this->admin());

        foreach (['draft', 'inactive', 'archived'] as $status) {
            $channel = $this->channel(ucfirst($status).' one', status: $status);
            $this->page($channel)->assertOk()->assertInertia(fn (Assert $page) => $page->where('preview', $status));
        }
    }

    public function test_the_preview_flag_is_false_for_an_administrator_on_an_active_channel(): void
    {
        $channel = $this->channel('Live');
        $this->actingAs($this->admin());

        $this->page($channel)->assertInertia(fn (Assert $page) => $page->where('preview', null));
    }

    // Content

    public function test_only_published_upcoming_members_are_listed_soonest_first(): void
    {
        $cat = $this->category('Business');
        $channel = $this->channel('Business', [$cat]);
        $this->event('Second', [$cat], ['start_at' => now()->addDays(5)]);
        $this->event('First', [$cat], ['start_at' => now()->addDays(2)]);
        $this->event('Pending', [$cat], ['editorial_status' => 'pending_review']);
        $this->event('Candidate', [$cat], ['status' => 'candidate']);
        $this->event('Draft', [$cat], ['status' => 'draft']);
        $this->event('Rejected', [$cat], ['editorial_status' => 'rejected']);
        $this->event('Past', [$cat], ['start_at' => now()->subDays(3), 'end_at' => now()->subDays(3)->addHour()]);
        $this->event('Elsewhere', [$this->category('Other')]);
        $this->event('Excluded', [$cat]);
        $this->exclude($channel, Event::where('title', 'Excluded')->first());

        $this->page($channel)->assertInertia(fn (Assert $page) => $page
            ->has('events.data', 2)
            ->where('events.data.0.title', 'First')
            ->where('events.data.1.title', 'Second'));
    }

    public function test_a_curated_event_with_no_category_appears(): void
    {
        $channel = $this->channel('Veterans');
        $event = $this->event('Veterans lunch');
        DB::table('event_channels')->insert(['channel_id' => $channel->id, 'event_id' => $event->id, 'relationship_type' => 'curated', 'created_at' => now(), 'updated_at' => now()]);

        $this->page($channel)->assertInertia(fn (Assert $page) => $page->has('events.data', 1)->where('events.data.0.title', 'Veterans lunch'));
    }

    public function test_picks_are_featured_published_upcoming_events_soonest_first_and_capped_at_six(): void
    {
        $cat = $this->category('Business');
        $channel = $this->channel('Business', [$cat]);

        foreach (range(1, 8) as $i) {
            $this->feature($channel, $this->event("Pick {$i}", [$cat], ['start_at' => now()->addDays(10 - $i)]));
        }
        $this->event('Plain', [$cat]);
        $this->feature($channel, $this->event('Pending pick', [$cat], ['editorial_status' => 'pending_review']));
        $this->feature($channel, $this->event('Past pick', [$cat], ['start_at' => now()->subDay(), 'end_at' => now()->subHours(20)]));

        $this->page($channel)->assertInertia(fn (Assert $page) => $page
            ->has('picks', 6)
            ->where('picks.0.title', 'Pick 8')
            ->where('picks.5.title', 'Pick 3')
            ->where('picks.0.is_pick', true));
    }

    public function test_the_feed_is_complete_picks_are_repeated_in_it_and_marked(): void
    {
        $cat = $this->category('Business');
        $channel = $this->channel('Business', [$cat]);
        $this->event('Plain', [$cat], ['start_at' => now()->addDays(1)]);
        $this->feature($channel, $this->event('Picked', [$cat], ['start_at' => now()->addDays(2)]));
        $this->event('Later', [$cat], ['start_at' => now()->addDays(3)]);

        $this->page($channel)->assertInertia(fn (Assert $page) => $page
            ->has('events.data', 3)
            ->where('events.data.0.is_pick', false)
            ->where('events.data.1.title', 'Picked')
            ->where('events.data.1.is_pick', true)
            ->where('events.data.2.is_pick', false)
            ->has('picks', 1)
            ->where('picks.0.title', 'Picked'));
    }

    public function test_an_unpublished_pick_stays_invisible_and_never_publishes(): void
    {
        $channel = $this->channel('Business');
        $event = $this->event('Waiting', attributes: ['editorial_status' => 'pending_review']);
        $this->feature($channel, $event);

        $this->page($channel)->assertInertia(fn (Assert $page) => $page->has('picks', 0)->has('events.data', 0));
        $this->assertSame('pending_review', $event->fresh()->editorial_status);
    }

    public function test_the_feed_paginates_twenty_at_a_time(): void
    {
        $cat = $this->category('Business');
        $channel = $this->channel('Business', [$cat]);
        foreach (range(1, 25) as $i) {
            $this->event("E{$i}", [$cat], ['start_at' => now()->addHours($i)]);
        }

        $this->page($channel)->assertInertia(fn (Assert $page) => $page->has('events.data', 20)->where('events.data.0.title', 'E1'));
        $this->get(route('channels.show', [$channel->slug, 'page' => 2]))->assertInertia(fn (Assert $page) => $page->has('events.data', 5)->where('events.data.0.title', 'E21'));
    }

    public function test_an_empty_channel_still_renders(): void
    {
        $channel = $this->channel('Quiet');

        $this->page($channel)->assertOk()->assertInertia(fn (Assert $page) => $page->has('events.data', 0)->has('picks', 0)->has('curators', 0));
    }

    public function test_event_payloads_carry_only_the_card_fields(): void
    {
        $cat = $this->category('Business');
        $channel = $this->channel('Business', [$cat]);
        $this->event('Detailed', [$cat], ['description' => 'Internal long text', 'created_by_user_id' => $this->admin()->id, 'canonical_url' => 'https://example.org/e']);

        $json = $this->page($channel)->viewData('page')['props']['events']['data'][0];

        $this->assertEqualsCanonicalizing(
            ['id', 'uuid', 'title', 'slug', 'short_description', 'start_at', 'end_at', 'timezone', 'all_day', 'is_free', 'details_url', 'venue', 'organizer', 'categories', 'tags', 'audiences', 'is_pick'],
            array_keys($json),
        );
        $this->assertSame('https://example.org/e', $json['details_url']);
    }

    // Curator cards

    public function test_a_curator_card_shows_name_role_blurb_and_start_month(): void
    {
        $channel = $this->channel('Business');
        $user = $this->curator('curator', $channel, 'lead');
        CuratorAssignment::where('user_id', $user->id)->update(['started_at' => '2026-03-15 10:00:00']);
        $this->profile($user);

        $this->page($channel)->assertInertia(fn (Assert $page) => $page
            ->has('curators', 1)
            ->where('curators.0.display_name', 'Pat Public')
            ->where('curators.0.role', 'Lead curator')
            ->where('curators.0.blurb', 'I like events.')
            ->where('curators.0.since', 'March 2026'));
    }

    public function test_cards_are_hidden_for_a_private_profile_an_ended_assignment_a_removed_role_or_a_blank_name(): void
    {
        $channel = $this->channel('Business');

        $private = $this->curator('curator', $channel);
        $this->profile($private, ['is_public' => false]);

        $ended = $this->curator('curator', $channel);
        $this->profile($ended);
        CuratorAssignment::where('user_id', $ended->id)->update(['status' => 'ended']);

        $demoted = $this->curator('curator', $channel);
        $this->profile($demoted);
        $demoted->removeRole('curator');

        $blank = $this->curator('curator', $channel);
        $this->profile($blank, ['display_name' => '']);

        $noProfile = $this->curator('curator', $channel);

        $this->page($channel)->assertInertia(fn (Assert $page) => $page->has('curators', 0));
    }

    public function test_cards_are_ordered_lead_first_and_capped_at_three(): void
    {
        $channel = $this->channel('Business');

        foreach ([['contributor', 'C1'], ['curator', 'K1'], ['lead', 'L1'], ['curator', 'K2'], ['contributor', 'C2']] as $i => [$role, $name]) {
            $user = $this->curator('curator', $channel, $role);
            CuratorAssignment::where('user_id', $user->id)->update(['started_at' => now()->subDays(10 - $i)]);
            $this->profile($user, ['display_name' => $name]);
        }

        $this->page($channel)->assertInertia(fn (Assert $page) => $page
            ->has('curators', 3)
            ->where('curators.0.display_name', 'L1')
            ->where('curators.1.display_name', 'K1')
            ->where('curators.2.display_name', 'K2'));
    }

    public function test_cards_and_pages_never_contain_an_email_or_account_field(): void
    {
        $channel = $this->channel('Business');
        $user = $this->curator('curator', $channel, 'lead');
        $user->forceFill(['email' => 'secret.person@example.org', 'name' => 'Account Holder Legal Name'])->save();
        $this->profile($user, ['display_name' => 'Pat Public']);

        $response = $this->page($channel);
        $this->assertSame(['display_name', 'role', 'blurb', 'since'], array_keys($response->viewData('page')['props']['curators'][0]));
        $html = $response->getContent();
        $this->assertStringNotContainsString('secret.person@example.org', $html);
        $this->assertStringNotContainsString('Account Holder Legal Name', $html);

        $index = $this->get(route('channels.index'))->getContent();
        $this->assertStringNotContainsString('secret.person@example.org', $index);
        $this->assertStringNotContainsString('Account Holder Legal Name', $index);
    }

    public function test_a_blurb_with_markup_arrives_as_literal_text(): void
    {
        $channel = $this->channel('Business');
        $user = $this->curator('curator', $channel);
        $this->profile($user, ['blurb' => '<script>alert(1)</script><b>bold</b>']);

        $this->page($channel)->assertInertia(fn (Assert $page) => $page->where('curators.0.blurb', '<script>alert(1)</script><b>bold</b>'));
        $this->assertStringContainsString('whitespace-pre-line', file_get_contents(resource_path('js/pages/channels/show.tsx')));
        $this->assertStringNotContainsString('dangerouslySetInnerHTML', file_get_contents(resource_path('js/pages/channels/show.tsx')));
    }

    // Index and header flag

    public function test_the_index_lists_only_active_channels_in_order_with_counts_and_the_lead_curator(): void
    {
        $cat = $this->category('Business');
        $b = $this->channel('Bravo', [$cat]);
        $b->update(['sort_order' => 2]);
        $a = $this->channel('Alpha');
        $a->update(['sort_order' => 2]);
        $z = $this->channel('Zulu');
        $z->update(['sort_order' => 1]);
        $this->channel('Hidden draft', status: 'draft');
        $this->channel('Hidden archived', status: 'archived');
        $this->event('One', [$cat]);
        $this->event('Two', [$cat]);
        $this->event('Pending', [$cat], ['editorial_status' => 'pending_review']);

        $lead = $this->curator('curator', $b, 'lead');
        $this->profile($lead, ['display_name' => 'Lee Lead']);
        $other = $this->curator('curator', $b, 'curator');
        $this->profile($other, ['display_name' => 'Not The Lead']);
        // Alpha has a public curator but no lead, so it must show no curator line at all.
        $onlyCurator = $this->curator('curator', $a, 'curator');
        $this->profile($onlyCurator, ['display_name' => 'Only A Curator']);

        $this->get(route('channels.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('channels/index')
            ->has('channels', 3)
            ->where('channels.0.name', 'Zulu')
            ->where('channels.1.name', 'Alpha')
            ->where('channels.2.name', 'Bravo')
            ->where('channels.2.upcoming_count', 2)
            ->where('channels.2.lead_curator', 'Lee Lead')
            ->where('channels.1.lead_curator', null)
            ->where('channels.0.upcoming_count', 0));
    }

    public function test_the_index_count_equals_the_page_feed_total_even_with_picks(): void
    {
        $cat = $this->category('Business');
        $channel = $this->channel('Business', [$cat]);
        $this->feature($channel, $this->event('Picked', [$cat]));
        $this->event('Plain', [$cat]);

        $count = $this->get(route('channels.index'))->viewData('page')['props']['channels'][0]['upcoming_count'];
        $feed = $this->page($channel)->viewData('page')['props']['events']['total'] ?? count($this->page($channel)->viewData('page')['props']['events']['data']);

        $this->assertSame(2, $count);
        $this->assertSame($count, $feed);
    }

    public function test_an_empty_index_renders(): void
    {
        $this->get(route('channels.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('channels', 0));
    }

    public function test_the_header_flag_is_false_with_no_active_channel_and_true_with_one(): void
    {
        $this->channel('Draft only', status: 'draft');
        $this->get(route('events.index'))->assertInertia(fn (Assert $page) => $page->where('public_channels', false));

        $this->channel('Live');
        $this->get(route('events.index'))->assertInertia(fn (Assert $page) => $page->where('public_channels', true));
    }

    // Metadata and cost

    public function test_the_meta_description_uses_the_short_description_or_the_first_160_characters(): void
    {
        $with = $this->channel('With short');
        $with->update(['short_description' => 'A short summary.', 'description' => 'Long text']);
        $without = $this->channel('Without short');
        $without->update(['description' => str_repeat('word ', 80)]);
        $neither = $this->channel('Neither');

        $this->page($with)->assertInertia(fn (Assert $page) => $page->where('channel.meta_description', 'A short summary.'));
        $this->page($without)->assertInertia(fn (Assert $page) => $page->where('channel.meta_description', fn ($v) => strlen($v) <= 163 && str_ends_with($v, '...')));
        $this->page($neither)->assertInertia(fn (Assert $page) => $page->where('channel.meta_description', null));
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_events(): void
    {
        $cat = $this->category('Business');
        $channel = $this->channel('Business', [$cat]);
        $lead = $this->curator('curator', $channel, 'lead');
        $this->profile($lead);

        $make = function (int $n) use ($cat, $channel) {
            foreach (range(1, $n) as $i) {
                $event = $this->event('Event '.uniqid(), [$cat]);
                $event->tags()->create(['name' => 't'.uniqid(), 'slug' => uniqid()]);
                if ($i % 2) {
                    $this->feature($channel, $event);
                }
            }
        };
        $count = function () use ($channel) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->page($channel)->assertOk();
            $this->get(route('channels.index'))->assertOk();

            return count(DB::getQueryLog());
        };

        $make(2);
        $count(); // warms the permission cache, which loads once per process
        $few = $count();
        $make(14);
        $many = $count();

        $this->assertSame($few, $many, 'queries must not scale with events');
    }
}
