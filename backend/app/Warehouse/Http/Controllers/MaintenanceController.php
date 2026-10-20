<?php

namespace App\Warehouse\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Warehouse\Models\MaintenanceSchedule;
use App\Warehouse\Services\MaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaintenanceController extends ApiController
{
    public function __construct(private readonly MaintenanceService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request->user());
        $companyId = (int) $request->user()->company_id;

        $rows = MaintenanceSchedule::query()
            ->where('company_id', $companyId)
            ->when($request->string('status')->toString(), fn ($w, $s) => $w->where('status', $s))
            ->with(['asset:id,asset_tag,name,category', 'assignee:id,name'])
            ->orderBy('next_due_date')
            ->get()
            ->map(fn (MaintenanceSchedule $s) => [
                'id' => $s->id,
                'task_code' => $s->task_code,
                'title' => $s->title,
                'asset' => $s->asset ? [
                    'id' => $s->asset->id,
                    'asset_tag' => $s->asset->asset_tag,
                    'name' => $s->asset->name,
                    'category' => $s->asset->category,
                ] : null,
                'frequency_type' => $s->frequency_type,
                'frequency_value' => $s->frequency_value,
                'next_due_date' => $s->next_due_date?->toDateString(),
                'last_done_date' => $s->last_done_date?->toDateString(),
                'lead_alert_days' => $s->lead_alert_days,
                'is_overdue' => $s->is_overdue,
                'is_due_soon' => $s->is_due_soon,
                'assigned_dept' => $s->assigned_dept,
                'assignee' => $s->assignee ? ['id' => $s->assignee->id, 'name' => $s->assignee->name] : null,
                'estimated_cost' => (float) $s->estimated_cost,
                'status' => $s->status,
            ]);

        return $this->success([
            'data' => $rows,
            'upcoming' => $this->service->upcomingList($companyId, 30),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request->user());
        $sched = MaintenanceSchedule::where('company_id', $request->user()->company_id)
            ->with(['asset:id,asset_tag,name', 'logs.performer:id,name', 'assignee:id,name'])
            ->findOrFail($id);

        return $this->success([
            'id' => $sched->id,
            'task_code' => $sched->task_code,
            'title' => $sched->title,
            'description' => $sched->description,
            'asset' => $sched->asset ? ['id' => $sched->asset->id, 'asset_tag' => $sched->asset->asset_tag, 'name' => $sched->asset->name] : null,
            'frequency_type' => $sched->frequency_type,
            'frequency_value' => $sched->frequency_value,
            'next_due_date' => $sched->next_due_date?->toDateString(),
            'last_done_date' => $sched->last_done_date?->toDateString(),
            'lead_alert_days' => $sched->lead_alert_days,
            'assigned_dept' => $sched->assigned_dept,
            'assignee' => $sched->assignee ? ['id' => $sched->assignee->id, 'name' => $sched->assignee->name] : null,
            'estimated_cost' => (float) $sched->estimated_cost,
            'status' => $sched->status,
            'is_overdue' => $sched->is_overdue,
            'is_due_soon' => $sched->is_due_soon,
            'logs' => $sched->logs->map(fn ($l) => [
                'id' => $l->id,
                'performed_date' => $l->performed_date?->toDateString(),
                'actual_cost' => (float) $l->actual_cost,
                'parts_used' => $l->parts_used,
                'notes' => $l->notes,
                'issues_found' => $l->issues_found,
                'result' => $l->result,
                'performer' => $l->performer ? ['id' => $l->performer->id, 'name' => $l->performer->name] : null,
                'created_at' => $l->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireAdmin($request->user());
        $data = $request->validate([
            'fixed_asset_id' => ['required', 'exists:fixed_assets,id'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'frequency_type' => ['required', 'in:monthly,quarterly,semiannual,annual,every_n_days'],
            'frequency_value' => ['nullable', 'integer', 'min:1'],
            'next_due_date' => ['required', 'date'],
            'lead_alert_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'assigned_dept' => ['nullable', 'string', 'max:100'],
            'assigned_user_id' => ['nullable', 'string', 'size:26'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $sched = $this->service->createSchedule($request->user(), $data);

        return $this->created([
            'id' => $sched->id,
            'task_code' => $sched->task_code,
        ], 'Maintenance schedule dibuat.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request->user());
        $sched = MaintenanceSchedule::where('company_id', $request->user()->company_id)->findOrFail($id);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'frequency_type' => ['nullable', 'in:monthly,quarterly,semiannual,annual,every_n_days'],
            'frequency_value' => ['nullable', 'integer', 'min:1'],
            'next_due_date' => ['nullable', 'date'],
            'lead_alert_days' => ['nullable', 'integer'],
            'assigned_dept' => ['nullable', 'string', 'max:100'],
            'assigned_user_id' => ['nullable', 'string', 'size:26'],
            'estimated_cost' => ['nullable', 'numeric'],
            'status' => ['nullable', 'in:active,paused,completed'],
        ]);
        $sched->update($data);

        return $this->success(['task_code' => $sched->task_code], 'Diperbarui.');
    }

    public function logExecution(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request->user());
        $sched = MaintenanceSchedule::where('company_id', $request->user()->company_id)->findOrFail($id);
        $data = $request->validate([
            'performed_date' => ['required', 'date'],
            'actual_cost' => ['nullable', 'numeric', 'min:0'],
            'parts_used' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'issues_found' => ['nullable', 'string'],
            'result' => ['nullable', 'in:ok,needs_repair,critical'],
        ]);
        $log = $this->service->logExecution($request->user(), $sched, $data);

        return $this->created([
            'log_id' => $log->id,
            'next_due_date' => $sched->fresh()->next_due_date?->toDateString(),
        ], 'Maintenance dicatat & jadwal di-roll forward.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request->user());
        $sched = MaintenanceSchedule::where('company_id', $request->user()->company_id)->findOrFail($id);
        $sched->delete();

        return $this->success(['task_code' => $sched->task_code], 'Dihapus.');
    }
}
