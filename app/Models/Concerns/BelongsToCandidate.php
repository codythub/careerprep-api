<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\UserRole;
use LogicException;

/**
 * Candidate data (resumes, payments, reviews) may never belong to an admin account.
 */
trait BelongsToCandidate
{
    public static function bootBelongsToCandidate(): void
    {
        static::saving(function (self $model): void {
            if (! $model->isDirty('user_id')) {
                return;
            }

            $ownerIsAdmin = User::query()
                ->whereKey($model->user_id)
                ->where('role', UserRole::Admin)
                ->exists();

            if ($ownerIsAdmin) {
                throw new LogicException(class_basename($model).' records cannot belong to an admin account.');
            }
        });
    }
}
