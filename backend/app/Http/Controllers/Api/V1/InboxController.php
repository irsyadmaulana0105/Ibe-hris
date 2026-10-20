<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditLog;
use App\Models\Leave;
use App\Models\Overtime;
use App\Models\Payroll;
use App\Models\Reimbursement;
use App\Models\SalaryAdvance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin inbox: pending items the current user is responsible for approving,
 * plus a feed of recent notification events. Powers the bell icon dropdown
 * in the admin topbar - the badge count = total pending items the user can
 * personally approve based on their capabilities.
 */
class InboxController extends ApiController
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $can = fn (string $cap) => method_exists($user, 'hasCapability') && $user->hasCapability($cap);

        // Pending counts - only fetched for buckets the user can actually action
        $pending = [
            'leave'             => $can('leave.manage')           ? Leave::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'pending')->count() : 0,
            'overtime'          => $can('overtime.manage')        ? Overtime::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'pending')->count() : 0,
            'reimburse'         => $can('reimbursement.manage')   ? Reimbursement::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'pending')->count() : 0,
            'kasbon'            => $can('salary_advance.manage')  ? SalaryAdvance::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'pending')->count() : 0,
            'payroll_submitted' => $can('payroll.approve') || $can('payroll.manage')
                ? Payroll::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'submitted')->count()
                : 0,
            'payroll_draft'     => $can('payroll.submit') || $can('payroll.manage')
                ? Payroll::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'draft')->count()
                : 0,
        ];

        // Recent activity feed - notification events + approval actions affecting
        // either the user (as actor) or items in their company
        $recent = AuditLog::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('action', [
                'leave.approved', 'leave.rejected',
                'overtime.approved', 'overtime.rejected',
                'reimbursement.approved', 'reimbursement.rejected',
                'salary_advance.approved', 'salary_advance.rejected',
                'payroll.submitted', 'payroll.bulk_submitted',
                'payroll.approved', 'payroll.paid',
            ])
            ->orderByDesc('created_at')
            ->limit(15)
            ->with('actor:id,name,email')
            ->get()
            ->map(fn (AuditLog $log) => [
                'id'         => $log->id,
                'action'     => $log->action,
                'entity_id'  => $log->entity_id,
                'meta'       => $log->meta,
                'created_at' => $log->created_at?->toISOString(),
                'actor_name' => $log->actor?->name,
            ]);

        return $this->success([
            'pending' => $pending,
            'pending_total' => array_sum($pending),
            'recent' => $recent,
        ]);
    }
}
