<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\LeaveResource;
use App\Models\Leave;
use App\Models\User;
use App\Services\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LeaveController extends ApiController
{
    public function __construct(private readonly LeaveService $service) {}

    // ── Employee ──────────────────────────────────────────────────────────────

    /** GET /api/v1/leaves */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $leaves = $this->service->listForUser($user);
        $quota = $this->service->quotaForUser($user);
        $usedByType = $this->service->usedDaysByType($user, now()->year);

        return $this->paginated($leaves, LeaveResource::collection($leaves), [
            'quota' => $quota ? ['annual_days' => $quota->annual_days, 'sick_days' => $quota->sick_days] : null,
            'annual_balance' => $user->annual_leave_balance ?? 12,
            'used_by_type' => $usedByType,
        ]);
    }

    /** POST /api/v1/leaves */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'leave_type' => ['required', Rule::in(array_keys(Leave::$leaveTypes))],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'attachment' => ['prohibited'],
        ]);

        $leave = $this->service->submit($request->user(), $data, $request->file('attachment'));

        return $this->created(new LeaveResource($leave), __('Leave request submitted.'));
    }

    /** DELETE /api/v1/leaves/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->service->cancel($request->user(), $id);

        return $this->success(message: __('Leave request cancelled.'));
    }

    // ── Admin ─────────────────────────────────────────────────────────────────

    /** GET /api/v1/admin/leaves */
    public function adminIndex(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'leave.manage', 'Leave management access required.');

        $leaves = $this->service->listForAdmin(
            $request->user(),
            $request->only(['status', 'month', 'division', 'search'])
        );

        return $this->paginated($leaves, LeaveResource::collection($leaves), [
            'pending_count' => $this->service->pendingCount($request->user()->company_id),
            'summary' => $this->service->summaryForAdmin(
                $request->user(),
                $request->only(['month', 'division', 'search'])
            ),
        ]);
    }

    /** GET /api/v1/admin/leaves/{id} */
    public function adminShow(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'leave.manage', 'Leave management access required.');

        return $this->success(new LeaveResource($this->service->detail($id)));
    }

    /** POST /api/v1/admin/leaves/{id}/approve */
    public function approve(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'leave.manage', 'Leave management access required.');
        $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        return $this->success(
            new LeaveResource($this->service->approve($request->user(), $id, $request->notes)),
            __('Leave request approved.')
        );
    }

    /** POST /api/v1/admin/leaves/{id}/reject */
    public function reject(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'leave.manage', 'Leave management access required.');
        $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        return $this->success(
            new LeaveResource($this->service->reject($request->user(), $id, $request->notes)),
            __('Leave request rejected.')
        );
    }

    /**
     * POST /api/v1/admin/leaves/{id}/cancel
     * Batalkan cuti yang sudah approved/rejected → cancelled.
     * Revert attendance + kembalikan saldo cuti tahunan.
     */
    public function cancelApproved(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'leave.manage', 'Leave management access required.');
        $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->success(
            new LeaveResource($this->service->cancelApproved($request->user(), $id, $request->reason)),
            'Cuti berhasil dibatalkan. Saldo cuti & absensi sudah dikembalikan.'
        );
    }

    /**
     * GET /api/v1/admin/leaves/balance-report?year=2026
     *
     * Per-employee leave balance summary: initial quota, used (approved YTD),
     * pending, remaining, breakdown per leave type.
     */
    public function balanceReport(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'leave.manage', 'Leave management access required.');
        $year = (int) ($request->integer('year') ?: date('Y'));
        $companyId = (int) $request->user()->company_id;

        $includeInactive = $request->boolean('include_inactive');
        $employees = User::query()
            ->where('company_id', $companyId)
            ->where('group', 'user')
            ->when(! $includeInactive, fn ($q) => $q->whereNotIn('employment_status', User::INACTIVE_STATUSES))
            ->select(['id', 'name', 'email', 'division_id', 'employment_status', 'annual_leave_balance'])
            ->orderBy('name')
            ->get();

        $startOfYear = "$year-01-01";
        $endOfYear = "$year-12-31";

        // Aggregate leaves per user
        $leaves = DB::table('leaves')
            ->where('company_id', $companyId)
            ->whereBetween('start_date', [$startOfYear, $endOfYear])
            ->select('user_id', 'leave_type', 'status', DB::raw('SUM(days_count) as total_days'), DB::raw('COUNT(*) as count'))
            ->groupBy('user_id', 'leave_type', 'status')
            ->get();

        $byUser = [];
        foreach ($leaves as $l) {
            $uid = $l->user_id;
            if (! isset($byUser[$uid])) {
                $byUser[$uid] = [
                    'used_annual' => 0, 'used_sick' => 0, 'used_personal' => 0, 'used_other' => 0,
                    'pending_total' => 0, 'rejected_total' => 0,
                ];
            }
            if ($l->status === 'approved') {
                $key = match ($l->leave_type) {
                    'annual' => 'used_annual',
                    'sick' => 'used_sick',
                    'personal' => 'used_personal',
                    default => 'used_other',
                };
                $byUser[$uid][$key] += (float) $l->total_days;
            } elseif ($l->status === 'pending') {
                $byUser[$uid]['pending_total'] += (float) $l->total_days;
            } elseif ($l->status === 'rejected') {
                $byUser[$uid]['rejected_total'] += (float) $l->total_days;
            }
        }

        $rows = $employees->map(function ($e) use ($byUser) {
            $stats = $byUser[$e->id] ?? ['used_annual' => 0, 'used_sick' => 0, 'used_personal' => 0, 'used_other' => 0, 'pending_total' => 0, 'rejected_total' => 0];
            $quota = (int) ($e->annual_leave_balance ?? 12);
            $remaining = max(0, $quota - $stats['used_annual']);

            return [
                'id' => $e->id,
                'name' => $e->name,
                'email' => $e->email,
                'division_id' => $e->division_id,
                'annual_quota' => $quota,
                'used_annual' => (float) $stats['used_annual'],
                'remaining' => round($remaining, 1),
                'used_sick' => (float) $stats['used_sick'],
                'used_personal' => (float) $stats['used_personal'],
                'used_other' => (float) $stats['used_other'],
                'pending_total' => (float) $stats['pending_total'],
                'rejected_total' => (float) $stats['rejected_total'],
                'utilization_pct' => $quota > 0 ? round(($stats['used_annual'] / $quota) * 100, 1) : 0,
            ];
        })->values()->all();

        // Roll-up summary
        $totalQuota = array_sum(array_column($rows, 'annual_quota'));
        $totalUsed = array_sum(array_column($rows, 'used_annual'));
        $totalPending = array_sum(array_column($rows, 'pending_total'));
        $totalSick = array_sum(array_column($rows, 'used_sick'));

        return $this->success([
            'year' => $year,
            'data' => $rows,
            'employee_count' => count($rows),
            'total_quota' => $totalQuota,
            'total_used_annual' => round($totalUsed, 1),
            'total_remaining' => round($totalQuota - $totalUsed, 1),
            'total_pending' => round($totalPending, 1),
            'total_sick' => round($totalSick, 1),
            'company_utilization_pct' => $totalQuota > 0 ? round(($totalUsed / $totalQuota) * 100, 1) : 0,
        ]);
    }
}
