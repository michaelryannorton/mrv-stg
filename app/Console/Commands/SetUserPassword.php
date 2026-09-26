<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class SetUserPassword extends Command
{
    protected $signature = 'user:set-password {email}';

    protected $description = 'Interactively set (or reset) a user\'s password, with hidden input. Run this directly over your own SSH session — never pass a password as a command argument.';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("No user found with email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $password = $this->secret('New password (input hidden, min 8 characters)');

        $validator = Validator::make(['password' => $password], ['password' => 'required|string|min:8']);

        if ($validator->fails()) {
            $this->error($validator->errors()->first('password'));

            return self::FAILURE;
        }

        $user->password = Hash::make($password);
        $user->save();

        $this->info("Password updated for {$user->email}.");

        return self::SUCCESS;
    }
}
