<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the Phase 2 roles and permissions and makes the existing owner account an
 * administrator (see community/Community Phase 2 Slice 1 Plan.md, section 4).
 *
 * This is a migration, not a seeder, on purpose: the deploy sequence has no seeding step, so a
 * role assignment that lives in a seeder could be forgotten and lock the owner out of /admin the
 * moment the role middleware ships. Written against the tables directly, not the Role/Permission
 * models, so it keeps working if those models change later. Safe to re-run: every insert ignores
 * duplicates.
 */
return new class extends Migration
{
    private const GUARD = 'web';

    private const USER_MODEL = 'App\\Models\\User';

    private const PERMISSIONS = [
        'channels.curate',
        'profile.edit',
        'events.publish',
        'events.edit',
        'channels.manage',
        'users.manage',
        'submissions.review',
        'clips.create',
    ];

    /** Role => permissions. The administrator holds every permission, including any added later. */
    private function rolePermissions(): array
    {
        return [
            'administrator' => self::PERMISSIONS,
            'editor' => ['channels.curate', 'profile.edit'],
            'curator' => ['channels.curate', 'profile.edit'],
            'organization_manager' => [],
        ];
    }

    public function up(): void
    {
        $userIds = DB::table('users')->pluck('id');

        // Only the single owner account is expected to exist. Granting administrator to anyone else
        // by default would be a silent privilege escalation, so stop and let a human decide.
        if ($userIds->count() > 1) {
            throw new RuntimeException(
                "Expected one owner account but found {$userIds->count()} users; refusing to grant administrator ".
                'to all of them. Run this migration after assigning roles manually with `php artisan user:assign-role`.'
            );
        }

        $now = now();

        foreach (self::PERMISSIONS as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => self::GUARD, 'created_at' => $now, 'updated_at' => $now]);
        }

        foreach (array_keys($this->rolePermissions()) as $name) {
            DB::table('roles')->insertOrIgnore(['name' => $name, 'guard_name' => self::GUARD, 'created_at' => $now, 'updated_at' => $now]);
        }

        $permissionIds = DB::table('permissions')->where('guard_name', self::GUARD)->pluck('id', 'name');
        $roleIds = DB::table('roles')->where('guard_name', self::GUARD)->pluck('id', 'name');

        foreach ($this->rolePermissions() as $role => $permissions) {
            foreach ($permissions as $permission) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionIds[$permission],
                    'role_id' => $roleIds[$role],
                ]);
            }
        }

        foreach ($userIds as $userId) {
            DB::table('model_has_roles')->insertOrIgnore([
                'role_id' => $roleIds['administrator'],
                'model_type' => self::USER_MODEL,
                'model_id' => $userId,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // The Spatie tables cascade, so removing the roles and permissions removes their links too.
        DB::table('roles')->where('guard_name', self::GUARD)->whereIn('name', array_keys($this->rolePermissions()))->delete();
        DB::table('permissions')->where('guard_name', self::GUARD)->whereIn('name', self::PERMISSIONS)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
