<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

/**
 * Creates the first administrator on an empty copy of the app (production, 2026-11-20). Registration is
 * removed from the app and user:set-password only changes an existing account, so without this a new
 * copy has no way to get its first account. Refuses once any administrator exists: every later account
 * comes by invitation from /admin/users. The password is typed at a hidden prompt; run it in your own
 * SSH session and never pass a password as an argument.
 */
class CreateAdministrator extends Command
{
    protected $signature = 'user:create-admin {email}';

    protected $description = 'Create the first administrator account, with hidden password input; refuses if an administrator already exists';

    public function handle(): int
    {
        $role = Role::where('name', 'administrator')->where('guard_name', 'web')->first();
        if (! $role) {
            $this->error('The administrator role does not exist. Run php artisan migrate --force first.');

            return self::FAILURE;
        }
        if ($role->users()->exists()) {
            $this->error('An administrator already exists. Invite further accounts from /admin/users.');

            return self::FAILURE;
        }

        $email = strtolower(trim((string) $this->argument('email')));
        $emailCheck = Validator::make(['email' => $email], ['email' => 'required|string|email|max:255|unique:users,email']);
        if ($emailCheck->fails()) {
            $this->error($emailCheck->errors()->first('email'));

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Name'));
        if ($name === '' || mb_strlen($name) > 255) {
            $this->error('A name of 1 to 255 characters is required.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (input hidden, min 8 characters)');
        $passwordCheck = Validator::make(['password' => $password], ['password' => 'required|string|min:8']);
        if ($passwordCheck->fails()) {
            $this->error($passwordCheck->errors()->first('password'));

            return self::FAILURE;
        }
        if (! hash_equals($password, (string) $this->secret('Repeat the password'))) {
            $this->error('The two passwords do not match. Nothing was created.');

            return self::FAILURE;
        }

        $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make($password)]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole($role);

        $this->info("Administrator created for {$user->email}.");

        return self::SUCCESS;
    }
}
