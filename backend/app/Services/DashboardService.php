<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Attendance;
use App\Models\Leave;
use App\Models\Overtime;
use App\Models\Payroll;
use App\Models\SalaryAdvance;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Carbon;

class DashboardService
{
    /**
     * All data needed for the employee home/dashboard screen.
     * Extracted from Employee/HomeComponent to enable API + Livewire reuse.
     */
    public function employeeDashboard(User $user): array
    {
        $today = today();

        $attendance = Attendance::where('user_id', $user->id)
            ->where('date', $today->toDateString())
            ->first();

        $shift = $attendance?->shift ?? Shift::withoutGlobalScopes()
            ->where('company_id', $user->company_id)
            ->first();

        $latestPayroll = Payroll::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->first();

        $recentLeaves = Leave::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();

        return [
            'attendance'            => $attendance,
            'shift'                 => $shift,
            'annual_balance'        => $user->annual_leave_balance ?? 12,
            'pending_leave'         => $this->pendingCount(Leave::class, $user->id),
            'pending_overtime'      => $this->pendingCount(Overtime::class, $user->id),
            'pending_salary_advance' => $this->pendingCount(SalaryAdvance::class, $user->id),
            'latest_payroll'        => $latestPayroll,
            'recent_leaves'         => $recentLeaves,
            'admin_summary'         => $this->adminSummary($user),
            'admin_reporting'       => $this->adminReporting($user),
            'recent_audit_logs'     => $this->recentAuditLogs($user),
        ];
    }

    public function adminSummary(User $user): ?array
    {
        if (!$user->isAdmin) {
            return null;
        }

        $companyId = $user->company_id;

        return [
            'pending_leave_count' => $user->hasCapability('leave.manage')
                ? app(LeaveService::class)->pendingCount($companyId)
                : null,
            'pending_overtime_count' => $user->hasCapability('overtime.manage')
                ? app(OvertimeService::class)->pendingCount($companyId)
                : null,
            'pending_salary_advance_count' => $user->hasCapability('salary_advance.manage')
                ? app(SalaryAdvanceService::class)->pendingCount($companyId)
                : null,
            'pending_reimbursement_count' => $user->hasCapability('reimbursement.manage')
                ? app(ReimbursementService::class)->pendingCount($companyId)
                : null,
            'pending_face_approval_count' => $user->hasCapability('face_approval.manage')
                ? app(FaceApprovalService::class)->pendingCount($companyId)
                : null,
        ];
    }

    public function adminReporting(User $user): ?array
    {
        if (!$user->isAdmin) {
            return null;
        }

        $month = Carbon::now()->format('Y-m');
        $periodLabel = Carbon::parse($month . '-01')->translatedFormat('F Y');
        $companyId = $user->company_id;

        $attendanceToday = null;
        if ($user->hasCapability('attendance.manage')) {
            $totalEmployees = User::query()
                ->where('company_id', $companyId)
                ->where('group', 'user')
                ->active()
                ->count();

            $todayRecords = Attendance::withoutGlobalScopes()
                ->whereDate('date', today()->toDateString())
                ->whereHas('user', fn ($query) => $query->where('company_id', $companyId))
                ->get(['status']);

            $attendanceToday = [
                'total_employees' => $totalEmployees,
                'checked_in' => $todayRecords->count(),
                'late' => $todayRecords->where('status', 'late')->count(),
                'sick' => $todayRecords->where('status', 'sick')->count(),
                'excused' => $todayRecords->where('status', 'excused')->count(),
                'absent_or_missing' => max(0, $totalEmployees - $todayRecords->count()),
            ];
        }

        return [
            'period' => [
                'month' => $month,
                'label' => $periodLabel,
            ],
            'attendance_today' => $attendanceToday,
            'leave' => $user->hasCapability('leave.manage')
                ? app(LeaveService::class)->summaryForAdmin($user, ['month' => $month])
                : null,
            'overtime' => $user->hasCapability('overtime.manage')
                ? app(OvertimeService::class)->summaryForAdmin($user, ['month' => $month])
                : null,
            'reimbursement' => $user->hasCapability('reimbursement.manage')
                ? app(ReimbursementService::class)->summaryForAdmin($user, ['month' => $month])
                : null,
            'salary_advance' => $user->hasCapability('salary_advance.manage')
                ? app(SalaryAdvanceService::class)->summaryForAdmin($user, ['month' => $month])
                : null,
            'payroll' => $user->hasCapability('payroll.manage')
                ? app(AdminPayrollService::class)->summary($user, $month)
                : null,
        ];
    }

    public function recentAuditLogs(User $user, int $limit = 5)
    {
        if (!$user->hasCapability('audit_logs.view')) {
            return collect();
        }

        return AuditLog::query()
            ->with('actor:id,name,email,group')
            ->where('company_id', $user->company_id)
            ->latest()
            ->limit($limit)
            ->get();
    }

    private function pendingCount(string $model, string $userId): int
    {
        return $model::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->count();
    }
}
