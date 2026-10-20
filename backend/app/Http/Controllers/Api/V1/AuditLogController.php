<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'audit_logs.view', 'Audit log access required.');

        $logs = AuditLog::withoutGlobalScopes()
            ->where('company_id', $user->company_id)
            ->with('actor:id,name,email,group')
            ->when($request->filled('action'), fn ($query) => $query->where('action', $request->string('action')))
            ->when($request->filled('entity_type'), fn ($query) => $query->where('entity_type', $request->string('entity_type')))
            ->orderByDesc('created_at')
            ->paginate((int) ($request->integer('per_page') ?: 20));

        $rows = collect($logs->items())->map(function (AuditLog $log) {
            return [
                'id' => $log->id,
                'action' => $log->action,
                'entity_type' => $log->entity_type,
                'entity_id' => $log->entity_id,
                'meta' => $log->meta,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'created_at' => $log->created_at?->toISOString(),
                'actor' => $log->actor ? [
                    'id' => $log->actor->id,
                    'name' => $log->actor->name,
                    'email' => $log->actor->email,
                    'group' => $log->actor->group,
                ] : null,
            ];
        })->values();

        return $this->paginated($logs, $rows);
    }
}
