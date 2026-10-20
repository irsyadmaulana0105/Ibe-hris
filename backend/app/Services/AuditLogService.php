<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogService
{
    public function log(
        string $action,
        ?User $actor = null,
        ?string $entityType = null,
        string|int|null $entityId = null,
        array $meta = [],
        ?Request $request = null,
    ): AuditLog {
        return AuditLog::withoutGlobalScopes()->create([
            'company_id' => $actor?->company_id,
            'actor_id' => $actor?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId !== null ? (string) $entityId : null,
            'meta' => $meta === [] ? null : $meta,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
