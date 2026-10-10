<?php

namespace Tests\Feature\Curation;

use App\Models\Category;
use App\Models\Channel;
use App\Models\CuratorAssignment;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class CurationTestCase extends TestCase
{
    use RefreshDatabase;

    protected function category(string $name): Category
    {
        return Category::create(['name' => $name, 'slug' => Str::slug($name)]);
    }

    protected function channel(string $name, array $categories = [], string $status = 'active'): Channel
    {
        $channel = Channel::create(['name' => $name, 'slug' => Str::slug($name), 'status' => $status]);
        $channel->categories()->sync(collect($categories)->pluck('id')->all());

        return $channel;
    }

    protected function event(string $title, array $categories = [], array $attributes = []): Event
    {
        $event = Event::create([
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::random(4),
            'start_at' => now()->addDays(3),
            'timezone' => 'America/Los_Angeles',
            'status' => 'scheduled',
            'editorial_status' => 'published',
            ...$attributes,
        ]);
        $event->categories()->sync(collect($categories)->pluck('id')->all());

        return $event;
    }

    protected function curator(string $role = 'curator', ?Channel $channel = null, string $assignmentRole = 'curator'): User
    {
        $user = User::factory()->create()->assignRole($role);
        if ($channel) {
            CuratorAssignment::create(['channel_id' => $channel->id, 'user_id' => $user->id, 'role' => $assignmentRole, 'status' => 'active', 'started_at' => now()]);
        }

        return $user;
    }

    protected function admin(): User
    {
        return User::factory()->create()->assignRole('administrator');
    }

    protected function act(Channel $channel, Event $event, string $action)
    {
        return $this->post(route('curate.channels.act', [$channel->slug, $event->id, $action]));
    }

    protected function state(Channel $channel, Event $event): ?string
    {
        return \DB::table('event_channels')->where('channel_id', $channel->id)->where('event_id', $event->id)->value('relationship_type');
    }
}
