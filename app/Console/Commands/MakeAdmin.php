<?php

namespace App\Console\Commands;

use App\Models\User;
use App\UserRole;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('careerprep:make-admin {email : Email of the admin account} {--revoke : Return the user to the candidate role}')]
#[Description('Grant (or with --revoke, remove) admin access, creating the account if needed. This is the only way to change a role.')]
class MakeAdmin extends Command
{
    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->error("{$email} is not a valid email address.");

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            if ($this->option('revoke')) {
                $this->error("No user found with email {$email}.");

                return self::FAILURE;
            }

            return $this->createAdmin($email);
        }

        $role = $this->option('revoke') ? UserRole::Candidate : UserRole::Admin;

        if ($user->role === $role) {
            $this->info("{$email} already has the {$role->value} role.");

            return self::SUCCESS;
        }

        $user->forceFill(['role' => $role])->save();

        if ($role === UserRole::Candidate) {
            // Signed-in admin sessions must not outlive the revocation.
            $user->tokens()->delete();
        }

        $this->info("{$email} now has the {$role->value} role.");

        return self::SUCCESS;
    }

    /**
     * Create a new admin account. The password is read from a hidden prompt so it
     * never appears in the command line or shell history.
     */
    private function createAdmin(string $email): int
    {
        if (! $this->input->isInteractive()) {
            $this->error("No user found with email {$email}. Run this command interactively to create the account.");

            return self::FAILURE;
        }

        if (! $this->confirm("No user found with email {$email}. Create a new admin account?")) {
            $this->error('No account was created.');

            return self::FAILURE;
        }

        $data = [
            'name' => trim((string) $this->ask('Name')),
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

        $user = new User(['name' => $data['name'], 'email' => $email, 'password' => $data['password']]);
        $user->forceFill(['role' => UserRole::Admin])->save();

        $this->info("{$email} created with the admin role.");

        return self::SUCCESS;
    }
}
