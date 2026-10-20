<?php

namespace App\Warehouse\Services;

use App\Models\User;
use App\Warehouse\Models\MaintenanceLog;
use App\Warehouse\Models\MaintenanceSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Preventive Maintenance service.
 *
 * Schedule recurrence calculation:
 *   - monthly:   +1 month
 *   - quarterly: +3 months
 *   - semiannual:+6 months
 *   - annual:    +12 months
 *   - every_n_days: + N days
 *
 * On log creation: roll next_due_date forward from performed_date.
 */
class MaintenanceService
{
    public function createSchedule(User $actor, array $data): MaintenanceSchedule
    {
        return MaintenanceSchedule::create([
            'company_id' => $actor->company_id,
            'fixed_asset_id' => $data['fixed_asset_id'],
            'task_code' => $data['task_code'] ?? $this->generateTaskCode((int) $data['fixed_asset_id']),
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'frequency_type' => $data['frequency_type'],
            'frequency_value' => (int) ($data['frequency_value'] ?? 1),
            'next_due_date' => $data['next_due_date'],
            'lead_alert_days' => (int) ($data['lead_alert_days'] ?? 7),
            'assigned_dept' => $data['assigned_dept'] ?? null,
            'assigned_user_id' => $data['assigned_user_id'] ?? null,
            'estimated_cost' => (float) ($data['estimated_cost'] ?? 0),
            'status' => 'active',
            'created_by' => $actor->id,
        ]);
    }

    public function generateTaskCode(int $fixedAssetId): string
    {
        $count = MaintenanceSchedule::where('fixed_asset_id', $fixedAssetId)->count();

        return 'PM-'.str_pad((string) ($count + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Log a performed maintenance + roll forward next_due_date.
     */
    public function logExecution(User $actor, MaintenanceSchedule $sched, array $data): MaintenanceLog
    {
        return DB::transaction(function () use ($actor, $sched, $data) {
            $perfDate = Carbon::parse($data['performed_date'] ?? now());

            $log = MaintenanceLog::create([
                'schedule_id' => $sched->id,
                'company_id' => $sched->company_id,
                'fixed_asset_id' => $sched->fixed_asset_id,
                'performed_date' => $perfDate->toDateString(),
                'actual_cost' => (float) ($data['actual_cost'] ?? 0),
                'parts_used' => $data['parts_used'] ?? null,
                'notes' => $data['notes'] ?? null,
                'issues_found' => $data['issues_found'] ?? null,
                'result' => $data['result'] ?? 'ok',
                'performed_by' => $actor->id,
                'verified_by' => $data['verified_by'] ?? null,
            ]);

            $sched->update([
                'last_done_date' => $perfDate->toDateString(),
                'next_due_date' => $this->computeNextDue($sched, $perfDate)->toDateString(),
            ]);

            return $log;
        });
    }

    public function computeNextDue(MaintenanceSchedule $sched, Carbon $from): Carbon
    {
        return match ($sched->frequency_type) {
            'monthly' => $from->copy()->addMonths(1),
            'quarterly' => $from->copy()->addMonths(3),
            'semiannual' => $from->copy()->addMonths(6),
            'annual' => $from->copy()->addYears(1),
            'every_n_days' => $from->copy()->addDays(max(1, $sched->frequency_value)),
            default => $from->copy()->addMonths(1),
        };
    }

    /**
     * Get schedules due within X days or overdue.
     */
    public function upcomingList(int $companyId, int $withinDays = 30): array
    {
        $rows = MaintenanceSchedule::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereDate('next_due_date', '<=', now()->addDays($withinDays)->toDateString())
            ->with('asset:id,asset_tag,name')
            ->orderBy('next_due_date')
            ->get();

        return $rows->map(fn ($s) => [
            'id' => $s->id,
            'task_code' => $s->task_code,
            'title' => $s->title,
            'asset_tag' => $s->asset?->asset_tag,
            'asset_name' => $s->asset?->name,
            'next_due_date' => $s->next_due_date?->toDateString(),
            'days_until' => (int) round(now()->diffInDays($s->next_due_date, false)),
            'is_overdue' => $s->is_overdue,
            'is_due_soon' => $s->is_due_soon,
            'estimated_cost' => (float) $s->estimated_cost,
        ])->all();
    }
}
