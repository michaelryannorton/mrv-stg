<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignUserRoleCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_assigns_a_role_and_is_idempotent(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test']);

        $this->artisan('user:assign-role', ['email' => 'owner@example.test', 'role' => 'administrator'])->assertSuccessful();
        $this->artisan('user:assign-role', ['email' => 'owner@example.test', 'role' => 'administrator'])->assertSuccessful();

        $this->assertCount(1, $user->fresh()->roles);
        $this->assertTrue($user->fresh()->hasRole('administrator'));
    }

    public function test_it_matches_email_case_insensitively(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test']);

        $this->artisan('user:assign-role', ['email' => 'Owner@Example.TEST', 'role' => 'curator'])->assertSuccessful();

        $this->assertTrue($user->fresh()->hasRole('curator'));
    }

    public function test_it_removes_a_role(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test'])->assignRole('curator');

        $this->artisan('user:assign-role', ['email' => 'owner@example.test', 'role' => 'curator', '--remove' => true])->assertSuccessful();

        $this->assertFalse($user->fresh()->hasRole('curator'));
    }

    public function test_it_fails_for_an_unknown_user_or_role(): void
    {
        User::factory()->create(['email' => 'owner@example.test']);

        $this->artisan('user:assign-role', ['email' => 'nobody@example.test', 'role' => 'curator'])->assertFailed();
        $this->artisan('user:assign-role', ['email' => 'owner@example.test', 'role' => 'wizard'])->assertFailed();
    }
}
