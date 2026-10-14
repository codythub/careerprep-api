<?php

namespace App\Console\Commands;

use App\Models\User;
use App\UserRole;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('careerprep:remove-admin {email : Email of the admin account} {--force : Skip the confirmation prompt}')]
#[Description('Revoke all tokens for an admin account and delete it. Admins are never turned into candidates.')]
class RemoveAdmin extends Command
{
    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $admin = User::query()->where('email', $email)->first();

        if ($admin === null) {
            $this->error("No account found with email {$email}.");

            return self::FAILURE;
        }

        if ($admin->role !== UserRole::Admin) {
            $this->error("{$email} is a candidate account. remove-admin only deletes admin accounts.");

            return self::FAILURE;
        }

        if ($admin->resumes()->exists() || $admin->payments()->exists() || $admin->reviews()->exists()) {
            $this->error("{$email} has candidate data (resumes, payments or reviews). Deleting it would delete that data; resolve it first.");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Delete admin account {$email} and sign it out everywhere?")) {
            $this->error('Nothing was changed.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($admin): void {
            $admin->tokens()->delete();
            $admin->delete();
        });

        $this->info("Admin account {$email} deleted. Its audit log entries keep the email address.");

        return self::SUCCESS;
    }
}
