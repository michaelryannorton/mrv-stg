<?php

namespace Tests\Feature\Channels;

use App\Models\Category;
use App\Models\Channel;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChannelAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrator');
    }

    public function test_the_index_lists_channels_with_categories_and_event_counts(): void
    {
        $music = Category::create(['name' => 'Local Music', 'slug' => 'local-music']);
        $channel = Channel::create(['name' => 'Music', 'slug' => 'music', 'status' => 'active']);
        $channel->categories()->attach($music->id);
        Event::create(['title' => 'Concert', 'slug' => 'concert', 'start_at' => now()->addDay(), 'timezone' => 'America/Los_Angeles', 'status' => 'scheduled', 'editorial_status' => 'published'])
            ->categories()->attach($music->id);
        Event::create(['title' => 'Pending', 'slug' => 'pending', 'start_at' => now()->addDay(), 'timezone' => 'America/Los_Angeles'])
            ->categories()->attach($music->id);

        $this->actingAs($this->admin())->get(route('admin.channels.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/channels/index')
                ->has('channels', 1)
                ->where('channels.0.name', 'Music')
                ->where('channels.0.categories.0.name', 'Local Music')
                ->where('channels.0.events_count', 2)
                ->where('channels.0.published_events_count', 1));
    }

    public function test_creating_a_channel_derives_the_slug_and_links_categories(): void
    {
        $music = Category::create(['name' => 'Local Music', 'slug' => 'local-music']);
        $food = Category::create(['name' => 'Food', 'slug' => 'food']);

        $this->actingAs($this->admin())->post(route('admin.channels.store'), [
            'name' => 'Live Music & Nightlife',
            'slug' => '',
            'status' => 'draft',
            'category_ids' => [$music->id, $food->id],
        ])->assertRedirect(route('admin.channels.index'));

        $channel = Channel::firstOrFail();
        $this->assertSame('live-music-nightlife', $channel->slug);
        $this->assertSame('draft', $channel->status);
        $this->assertSame(0, $channel->sort_order);
        $this->assertNotEmpty($channel->uuid);
        $this->assertEqualsCanonicalizing([$music->id, $food->id], $channel->categories()->pluck('categories.id')->all());
    }

    public function test_a_duplicate_slug_is_rejected_on_create(): void
    {
        Channel::create(['name' => 'Music', 'slug' => 'music', 'status' => 'draft']);

        $this->actingAs($this->admin())->post(route('admin.channels.store'), ['name' => 'Music', 'status' => 'draft'])
            ->assertSessionHasErrors('slug');

        $this->assertSame(1, Channel::count());
    }

    public function test_an_invalid_status_and_a_missing_name_are_rejected(): void
    {
        $this->actingAs($this->admin())->post(route('admin.channels.store'), ['name' => '', 'status' => 'live'])
            ->assertSessionHasErrors(['name', 'status']);
    }

    public function test_a_nonexistent_category_is_rejected(): void
    {
        $this->actingAs($this->admin())->post(route('admin.channels.store'), ['name' => 'Music', 'status' => 'draft', 'category_ids' => [999]])
            ->assertSessionHasErrors('category_ids.0');
    }

    public function test_updating_replaces_the_linked_categories_and_may_keep_its_own_slug(): void
    {
        $music = Category::create(['name' => 'Local Music', 'slug' => 'local-music']);
        $food = Category::create(['name' => 'Food', 'slug' => 'food']);
        $channel = Channel::create(['name' => 'Music', 'slug' => 'music', 'status' => 'draft']);
        $channel->categories()->attach($music->id);

        $this->actingAs($this->admin())->put(route('admin.channels.update', $channel), [
            'name' => 'Music and Food',
            'slug' => 'music',
            'status' => 'active',
            'sort_order' => 3,
            'category_ids' => [$food->id],
        ])->assertRedirect(route('admin.channels.index'));

        $channel->refresh();
        $this->assertSame('Music and Food', $channel->name);
        $this->assertSame('active', $channel->status);
        $this->assertSame(3, $channel->sort_order);
        $this->assertSame([$food->id], $channel->categories()->pluck('categories.id')->all());
    }

    public function test_a_slug_taken_by_another_channel_is_rejected_on_update(): void
    {
        Channel::create(['name' => 'Music', 'slug' => 'music', 'status' => 'draft']);
        $other = Channel::create(['name' => 'Food', 'slug' => 'food', 'status' => 'draft']);

        $this->actingAs($this->admin())->put(route('admin.channels.update', $other), ['name' => 'Food', 'slug' => 'music', 'status' => 'draft'])
            ->assertSessionHasErrors('slug');
    }

    public function test_the_edit_page_receives_the_channel_and_the_category_options(): void
    {
        $music = Category::create(['name' => 'Local Music', 'slug' => 'local-music']);
        $channel = Channel::create(['name' => 'Music', 'slug' => 'music', 'status' => 'active']);
        $channel->categories()->attach($music->id);

        $this->actingAs($this->admin())->get(route('admin.channels.edit', $channel))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/channels/edit')
                ->where('channel.slug', 'music')
                ->where('channel.category_ids', [$music->id])
                ->has('categories', 1)
                ->where('statuses', Channel::STATUSES));
    }

    public function test_a_curator_cannot_create_or_change_a_channel(): void
    {
        $channel = Channel::create(['name' => 'Music', 'slug' => 'music', 'status' => 'draft']);
        $this->actingAs(User::factory()->create()->assignRole('curator'));

        $this->post(route('admin.channels.store'), ['name' => 'New', 'status' => 'draft'])->assertForbidden();
        $this->put(route('admin.channels.update', $channel), ['name' => 'Changed', 'status' => 'active'])->assertForbidden();

        $this->assertSame(1, Channel::count());
        $this->assertSame('Music', $channel->fresh()->name);
    }
}
