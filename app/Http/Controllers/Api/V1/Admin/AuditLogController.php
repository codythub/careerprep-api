<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\AdminAuditLogResource;
use App\Models\AdminAuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'actor_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:64'],
        ]);

        $logs = AdminAuditLog::query()
            ->with('actor:id,name,email')
            ->when($validated['actor_id'] ?? null, fn (Builder $query, int $actorId) => $query->where('actor_id', $actorId))
            ->when($validated['action'] ?? null, fn (Builder $query, string $action) => $query->where('action', $action))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return AdminAuditLogResource::collection($logs);
    }
}
