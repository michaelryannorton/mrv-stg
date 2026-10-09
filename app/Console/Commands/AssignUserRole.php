<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

/**
 * Role assignment by email, mainly as the recovery path if the owner ever loses the administrator
 * role (see community/Community Phase 2 Slice 1 Plan.md, risks). Assigning a role does not touch
 * a password or any other credential. Idempotent.
 */
class AssignUserRole extends Command
{
    protected $signature = 'user:assign-role {email} {role : administrator, editor, curator or organization_manager} {--remove : Remove the role instead of assigning it}';

    protected $description = 'Assign (or with --remove, remove) a role for the user with the given email';

    public function handle(): int
    {
        $user = User::where('email', strtolower($this->argument('email')))->first();

        if (! $user) {
            $this->error("No user found with email {$this->argument('email')}.");

            return self::FAILURE;
        }

        if (! Role::where('name', $this->argument('role'))->where('guard_name', 'web')->exists()) {
            $this->error("Unknown role {$this->argument('role')}. Known roles: ".Role::pluck('name')->implode(', ').'.');

            return self::FAILURE;
        }

        if ($this->option('remove')) {
            $user->removeRole($this->argument('role'));
            $this->info("Removed {$this->argument('role')} from {$user->email}.");

            return self::SUCCESS;
        }

        $user->assignRole($this->argument('role'));
        $this->info("{$user->email} now has: ".$user->getRoleNames()->implode(', ').'.');

        return self::SUCCESS;
    }
}
