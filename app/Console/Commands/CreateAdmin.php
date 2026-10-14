<?php

namespace App\Console\Commands;

use App\Models\User;
use App\UserRole;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('careerprep:create-admin {email : Email for the new admin account} {--name= : Display name (asked for if omitted)}')]
#[Description('Create a new admin account. Admins are separate from candidates; existing accounts are never promoted.')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->error("{$email} is not a valid email address.");

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error("An account with email {$email} already exists. Admin accounts must use a new email address.");

            return self::FAILURE;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Run this command interactively: the password is entered at a hidden prompt.');

            return self::FAILURE;
        }

        // The password is read from hidden prompts so it never appears in the command line or shell history.
        $data = [
            'name' => trim((string) ($this->option('name') ?? $this->ask('Name'))),
            'password' => (string) $this->secret('Password (at least 8 characters)'),
            'password_confirmation' => (string) $this->secret('Confirm password'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'confirmed', Password::min(8), 'max:72'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            $this->error('No account was created.');

            return self::FAILURE;
        }

        $admin = new User(['name' => $data['name'], 'email' => $email, 'password' => $data['password']]);
        $admin->forceFill(['role' => UserRole::Admin])->save();

        $this->info("Admin account {$email} created.");

        return self::SUCCESS;
    }
}
