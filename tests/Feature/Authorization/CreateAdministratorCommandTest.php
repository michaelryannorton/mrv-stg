<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdministratorCommandTest extends TestCase
{
    use RefreshDatabase;

    private const NAME = 'Name';

    private const PASSWORD = 'Password (input hidden, min 8 characters)';

    private const REPEAT = 'Repeat the password';

    public function test_it_creates_the_first_administrator(): void
    {
        $this->artisan('user:create-admin', ['email' => 'Owner@Example.TEST'])
            ->expectsQuestion(self::NAME, 'Owner')
            ->expectsQuestion(self::PASSWORD, 'correct horse')
            ->expectsQuestion(self::REPEAT, 'correct horse')
            ->assertSuccessful();

        $user = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertSame('Owner', $user->name);
        $this->assertTrue($user->hasRole('administrator'));
        $this->assertTrue(Hash::check('correct horse', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->invited_at);
    }

    public function test_the_new_administrator_can_open_the_admin_area(): void
    {
        $this->artisan('user:create-admin', ['email' => 'owner@example.test'])
            ->expectsQuestion(self::NAME, 'Owner')
            ->expectsQuestion(self::PASSWORD, 'correct horse')
            ->expectsQuestion(self::REPEAT, 'correct horse')
            ->assertSuccessful();

        $this->actingAs(User::firstOrFail())->get('/admin/users')->assertOk();
    }

    public function test_it_refuses_when_an_administrator_exists(): void
    {
        User::factory()->create(['email' => 'first@example.test'])->assignRole('administrator');

        $this->artisan('user:create-admin', ['email' => 'second@example.test'])->assertFailed();

        $this->assertSame(1, User::count());
    }

    public function test_it_refuses_mismatched_or_short_passwords(): void
    {
        $this->artisan('user:create-admin', ['email' => 'owner@example.test'])
            ->expectsQuestion(self::NAME, 'Owner')
            ->expectsQuestion(self::PASSWORD, 'correct horse')
            ->expectsQuestion(self::REPEAT, 'correct horsf')
            ->assertFailed();

        $this->artisan('user:create-admin', ['email' => 'owner@example.test'])
            ->expectsQuestion(self::NAME, 'Owner')
            ->expectsQuestion(self::PASSWORD, 'short')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_refuses_an_invalid_or_taken_email(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);

        $this->artisan('user:create-admin', ['email' => 'not-an-email'])->assertFailed();
        $this->artisan('user:create-admin', ['email' => 'taken@example.test'])->assertFailed();

        $this->assertSame(1, User::count());
    }
}
