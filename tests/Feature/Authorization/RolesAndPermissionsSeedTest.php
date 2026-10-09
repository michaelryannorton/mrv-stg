<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolesAndPermissionsSeedTest extends TestCase
{
    use RefreshDatabase;

    private function runSeedMigration(): void
    {
        $migration = require database_path('migrations/2026_10_09_004500_seed_roles_and_permissions.php');
        $migration->up();
    }

    public function test_the_four_roles_exist(): void
    {
        $this->assertEqualsCanonicalizing(
            ['administrator', 'editor', 'curator', 'organization_manager'],
            Role::pluck('name')->all()
        );
    }

    public function test_administrator_holds_every_permission(): void
    {
        $this->assertGreaterThan(0, Permission::count());
        $this->assertEqualsCanonicalizing(
            Permission::pluck('name')->all(),
            Role::findByName('administrator')->permissions->pluck('name')->all(),
            'A permission exists that the administrator role does not hold; add it in the migration that created it.'
        );
    }

    public function test_editor_and_curator_get_only_channel_and_profile_permissions(): void
    {
        foreach (['editor', 'curator'] as $role) {
            $this->assertEqualsCanonicalizing(
                ['channels.curate', 'profile.edit'],
                Role::findByName($role)->permissions->pluck('name')->all()
            );
        }
    }

    public function test_curators_and_editors_cannot_publish_or_manage(): void
    {
        foreach (['editor', 'curator'] as $role) {
            $user = User::factory()->create()->assignRole($role);

            foreach (['events.publish', 'events.edit', 'channels.manage', 'users.manage', 'submissions.review', 'clips.create'] as $permission) {
                $this->assertFalse($user->can($permission), "A {$role} should not hold {$permission}.");
            }
        }
    }

    public function test_the_migration_makes_the_single_existing_user_an_administrator(): void
    {
        $owner = User::factory()->create();
        $this->assertFalse($owner->hasRole('administrator'));

        $this->runSeedMigration();

        $this->assertTrue($owner->fresh()->hasRole('administrator'));
    }

    public function test_the_migration_is_safe_to_run_twice(): void
    {
        $owner = User::factory()->create();
        $roleCount = Role::count();
        $permissionCount = Permission::count();

        $this->runSeedMigration();
        $this->runSeedMigration();

        $this->assertSame($roleCount, Role::count());
        $this->assertSame($permissionCount, Permission::count());
        $this->assertCount(1, $owner->fresh()->roles);
    }

    public function test_the_migration_refuses_when_more_than_one_user_exists(): void
    {
        User::factory()->count(2)->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected one owner account');

        try {
            $this->runSeedMigration();
        } finally {
            $this->assertSame(0, User::role('administrator')->count(), 'No user may be granted administrator when the migration refuses.');
        }
    }
}
