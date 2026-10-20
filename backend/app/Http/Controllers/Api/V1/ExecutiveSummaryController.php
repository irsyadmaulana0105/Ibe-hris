<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ApprovalRequest;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Executive summary untuk Direktur/CEO beranda.
 * Ringkas: pending action + headcount + recent activity.
 */
class ExecutiveSummaryController extends ApiController
{
    /** GET /api/v1/me/executive-summary */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $pendingContracts = Contract::where('company_id', $companyId)
            ->where('status', Contract::STATE_PENDING_DIREKTUR)
            ->count();

        $headcountActive = User::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('group', 'user')
            ->whereNotIn('employment_status', User::INACTIVE_STATUSES)
            ->count();

        $headcountNewThisMonth = User::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('group', 'user')
            ->whereMonth('join_date', now()->month)
            ->whereYear('join_date', now()->year)
            ->count();

        $recentSigned = Contract::where('company_id', $companyId)
            ->where('status', Contract::STATE_ARCHIVED)
            ->orderByDesc('materai_uploaded_at')
            ->limit(5)
            ->with('user:id,name')
            ->get()
            ->map(fn ($c) => [
                'id'              => $c->id,
                'contract_number' => $c->contract_number,
                'employee_name'   => $c->user?->name ?? '-',
                'signed_at'       => $c->materai_uploaded_at?->toIso8601String()
                    ?? $c->direktur_signed_at?->toIso8601String()
                    ?? $c->signed_at?->toIso8601String(),
            ]);

        $pendingPreviews = Contract::where('company_id', $companyId)
            ->where('status', Contract::STATE_PENDING_DIREKTUR)
            ->orderByDesc('karyawan_signed_at')
            ->limit(5)
            ->with('user:id,name')
            ->get()
            ->map(fn ($c) => [
                'id'            => $c->id,
                'employee_name' => $c->user?->name ?? '-',
                'contract_type' => match ($c->contract_type) {
                    'permanent'     => 'PKWTT',
                    'probation'     => 'Probation',
                    'training'      => 'Training',
                    'intern_campus' => 'Magang Kampus',
                    'intern_hub'    => 'Magang Independen',
                    'pkl_smk'       => 'PKL SMK',
                    default         => $c->contract_type,
                },
                'stage'         => 'Menunggu TTD Direktur',
            ]);

        return $this->success([
            'pending_contracts'         => $pendingContracts,
            'pending_approvals'         => ApprovalRequest::where('company_id', $companyId)->where('status', 'pending')->count(),
            'headcount_active'          => $headcountActive,
            'headcount_new_this_month'  => $headcountNewThisMonth,
            'recent_signed_contracts'   => $recentSigned,
            'pending_contract_previews' => $pendingPreviews,
        ]);
    }

    /** GET /api/v1/direktur/ringkasan-perusahaan */
    public function ringkasanPerusahaan(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $headcountActive = User::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('group', 'user')
            ->whereNotIn('employment_status', User::INACTIVE_STATUSES)
            ->count();

        $headcountPermanent = User::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('group', 'user')
            ->where('employment_status', 'permanent')
            ->count();

        $resignThisMonth = User::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('group', 'user')
            ->whereIn('employment_status', User::INACTIVE_STATUSES)
            ->whereMonth('updated_at', now()->month)
            ->whereYear('updated_at', now()->year)
            ->count();

        // Payroll bulan ini - sum dari user monthly amounts (rough estimate)
        $payrollCurrentMonth = User::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('group', 'user')
            ->whereNotIn('employment_status', User::INACTIVE_STATUSES)
            ->sum('salary_monthly');

        return $this->success([
            'headcount_active'      => $headcountActive,
            'headcount_permanent'   => $headcountPermanent,
            'payroll_current_month' => (int) $payrollCurrentMonth,
            'resign_this_month'     => $resignThisMonth,
        ]);
    }
}
