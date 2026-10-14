<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AdminAudit
{
    public function __construct(private Request $request) {}

    /**
     * Record an admin action. Callers pass small summaries only: never resume text,
     * provider payloads, storage paths or secrets.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(string $action, Model $target, ?array $before = null, ?array $after = null): AdminAuditLog
    {
        return AdminAuditLog::query()->create([
            'actor_id' => $this->request->user()?->getKey(),
            'action' => $action,
            'target_type' => strtolower(class_basename($target)),
            'target_id' => $target->getKey(),
            'before' => $before,
            'after' => $after,
            'ip_address' => $this->request->ip(),
        ]);
    }
}
