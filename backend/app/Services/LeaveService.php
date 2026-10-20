<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\LeaveQuota;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class LeaveService
{
    // ── Employee ──────────────────────────────────────────────────────────────

    public function submit(User $user, array $data, ?UploadedFile $attachment = null): Leave
    {
        $start = Carbon::parse($data['start_date']);
        $end = Carbon::parse($data['end_date']);
        $daysCount = $this->countWeekdays($start, $end);

        if ($attachment) {
            throw ValidationException::withMessages([
                'attachment' => 'Lampiran cuti dinonaktifkan sementara sampai penyimpanan privat tersedia.',
            ]);
        }

        return Leave::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'leave_type' => $data['leave_type'],
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'days_count' => $daysCount,
            'reason' => $data['reason'],
            'attachment' => null,
            'status' => 'pending',
        ]);
    }

    public function cancel(User $user, int $leaveId): void
    {
        $leave = Leave::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->findOrFail($leaveId);

        $leave->delete();
    }

    public function listForUser(User $user, int $perPage = 10): LengthAwarePaginator
    {
        return Leave::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function quotaForUser(User $user): ?LeaveQuota
    {
        return LeaveQuota::withoutGlobalScopes()
            ->where('company_id', $user->company_id)
            ->where('year', now()->year)
            ->first();
    }

    public function usedDaysByType(User $user, int $year): Collection
    {
        return Leave::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereYear('start_date', $year)
            ->selectRaw('leave_type, SUM(days_count) as total')
            ->groupBy('leave_type')
            ->pluck('total', 'leave_type');
    }

    // ── Admin ─────────────────────────────────────────────────────────────────

    public function listForAdmin(User $admin, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Leave::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->with(['user.division'])
            ->orderByRaw("
                CASE status
                    WHEN 'pending' THEN 0
                    WHEN 'approved' THEN 1
                    WHEN 'rejected' THEN 2
                    ELSE 3
                END
            ")
            ->orderBy('created_at', 'desc');

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['month'])) {
            $d = Carbon::parse($filters['month'].'-01');
            $query->whereYear('start_date', $d->year)
                ->whereMonth('start_date', $d->month);
        }

        if (! empty($filters['division'])) {
            $query->whereHas('user', fn ($q) => $q->where('division_id', $filters['division']));
        }

        if (! empty($filters['search'])) {
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%')
            );
        }

        return $query->paginate($perPage);
    }

    public function pendingCount(int $companyId): int
    {
        return Leave::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->count();
    }

    public function summaryForAdmin(User $admin, array $filters = []): array
    {
        $query = Leave::withoutGlobalScopes()
            ->where('company_id', $admin->company_id);

        if (! empty($filters['month'])) {
            $d = Carbon::parse($filters['month'].'-01');
            $query->whereYear('start_date', $d->year)
                ->whereMonth('start_date', $d->month);
        }

        if (! empty($filters['division'])) {
            $query->whereHas('user', fn ($q) => $q->where('division_id', $filters['division']));
        }

        if (! empty($filters['search'])) {
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%')
            );
        }

        $leaves = $query->get(['status', 'leave_type', 'days_count']);

        return [
            'total_requests' => $leaves->count(),
            'total_days' => (int) $leaves->sum('days_count'),
            'status_counts' => [
                'pending' => $leaves->where('status', 'pending')->count(),
                'approved' => $leaves->where('status', 'approved')->count(),
                'rejected' => $leaves->where('status', 'rejected')->count(),
            ],
            'type_counts' => [
                'annual' => $leaves->where('leave_type', 'annual')->count(),
                'sick' => $leaves->where('leave_type', 'sick')->count(),
                'personal' => $leaves->where('leave_type', 'personal')->count(),
                'medical_checkup' => $leaves->where('leave_type', 'medical_checkup')->count(),
                'other' => $leaves->where('leave_type', 'other')->count(),
            ],
        ];
    }

    public function approve(User $admin, int $leaveId, ?string $notes = null): Leave
    {
        $leave = $this->approvalQuery($admin)->with('user')->findOrFail($leaveId);
        $this->ensurePending($leave->status, 'Cuti');

        $leave->update([
            'status' => 'approved',
            'notes' => $notes ?: null,
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);

        $this->syncAttendanceRecords($leave);
        app(AuditLogService::class)->log(
            'leave.approved',
            $admin,
            'leave',
            $leave->id,
            [
                'user_id' => $leave->user_id,
                'status' => 'approved',
                'notes' => $notes,
            ]
        );

        // Fire transactional notification to the employee via configured channels
        if ($leave->user) {
            app(NotificationDispatcher::class)->dispatch('leave_approved', $leave->user, [
                'start_date' => $leave->start_date?->format('d M Y') ?? '-',
                'end_date' => $leave->end_date?->format('d M Y') ?? '-',
                'approver_name' => $admin->name,
                'notes' => $notes ?? '',
            ]);
        }

        return $leave->fresh();
    }

    public function reject(User $admin, int $leaveId, ?string $notes = null): Leave
    {
        $leave = $this->approvalQuery($admin)->with('user')->findOrFail($leaveId);
        $this->ensurePending($leave->status, 'Cuti');

        $leave->update([
            'status' => 'rejected',
            'notes' => $notes ?: null,
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);

        app(AuditLogService::class)->log(
            'leave.rejected',
            $admin,
            'leave',
            $leave->id,
            [
                'user_id' => $leave->user_id,
                'status' => 'rejected',
                'notes' => $notes,
            ]
        );

        if ($leave->user) {
            app(NotificationDispatcher::class)->dispatch('leave_rejected', $leave->user, [
                'start_date' => $leave->start_date?->format('d M Y') ?? '-',
                'end_date' => $leave->end_date?->format('d M Y') ?? '-',
                'rejection_reason' => $notes ?? 'Tidak disebutkan',
            ]);
        }

        return $leave->fresh();
    }

    /**
     * Batalkan cuti yang SUDAH approved (atau rejected) → status 'cancelled'.
     * Revert side-effect approval:
     *   - hapus attendance records 'excused'/'sick' yang dibuat dari cuti ini
     *   - kembalikan annual_leave_balance kalau leave_type = annual
     *
     * Admin-only. Dipakai untuk kasus salah approve / karyawan batal cuti
     * setelah disetujui.
     */
    public function cancelApproved(User $admin, int $leaveId, ?string $reason = null): Leave
    {
        $leave = $this->approvalQuery($admin)->with('user')->findOrFail($leaveId);

        if ($leave->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => 'Cuti ini sudah dibatalkan.']);
        }

        $wasApproved = $leave->status === 'approved';

        // Revert attendance records hanya kalau sebelumnya approved (yang bikin record)
        if ($wasApproved) {
            $this->revertAttendanceRecords($leave);

            // Kembalikan saldo cuti tahunan
            if ($leave->leave_type === 'annual') {
                $leave->user()->withoutGlobalScopes()->first()
                    ?->increment('annual_leave_balance', $leave->days_count);
            }
        }

        $prevStatus = $leave->status;
        $leave->update([
            'status' => 'cancelled',
            'notes' => $reason ?: $leave->notes,
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);

        app(AuditLogService::class)->log(
            'leave.cancelled',
            $admin,
            'leave',
            $leave->id,
            [
                'user_id' => $leave->user_id,
                'from_status' => $prevStatus,
                'to_status' => 'cancelled',
                'reason' => $reason,
                'balance_restored' => $wasApproved && $leave->leave_type === 'annual' ? $leave->days_count : 0,
            ]
        );

        if ($leave->user) {
            app(NotificationDispatcher::class)->dispatch('leave_cancelled', $leave->user, [
                'start_date' => $leave->start_date?->format('d M Y') ?? '-',
                'end_date' => $leave->end_date?->format('d M Y') ?? '-',
                'reason' => $reason ?? 'Dibatalkan oleh admin',
            ]);
        }

        return $leave->fresh();
    }

    private function approvalQuery(User $admin)
    {
        return Leave::withoutGlobalScopes()->where(function ($query) use ($admin) {
            $query->where('company_id', $admin->company_id)
                ->orWhere(function ($legacy) use ($admin) {
                    $legacy->whereNull('company_id')
                        ->whereHas('user', fn ($user) => $user->withoutGlobalScopes()
                            ->where('company_id', $admin->company_id));
                });
        });
    }

    private function ensurePending(string $status, string $label): void
    {
        if ($status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => "{$label} hanya dapat diproses saat berstatus pending.",
            ]);
        }
    }

    /**
     * Hapus attendance records yang dibuat oleh approval cuti ini.
     * Hanya hapus yang status-nya 'excused'/'sick' (yang dibuat syncAttendanceRecords),
     * jangan sentuh record absen normal (present/late) kalau ada overlap.
     */
    private function revertAttendanceRecords(Leave $leave): void
    {
        $cursor = Carbon::parse($leave->start_date);
        $end = Carbon::parse($leave->end_date);
        $leaveStatuses = ['excused', 'sick'];

        while ($cursor->lte($end)) {
            if (! $cursor->isWeekend()) {
                Attendance::withoutGlobalScopes()
                    ->where('user_id', $leave->user_id)
                    ->whereDate('date', $cursor->toDateString())
                    ->whereIn('status', $leaveStatuses)
                    ->delete();
            }
            $cursor->addDay();
        }
    }

    public function detail(int $leaveId): Leave
    {
        return Leave::withoutGlobalScopes()
            ->with(['user.division', 'user.jobTitle', 'approvedBy'])
            ->findOrFail($leaveId);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function countWeekdays(Carbon $start, Carbon $end): int
    {
        $count = 0;
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            if (! $cursor->isWeekend()) {
                $count++;
            }
            $cursor->addDay();
        }

        return max(1, $count);
    }

    private function syncAttendanceRecords(Leave $leave): void
    {
        $status = $leave->leave_type === 'sick' ? 'sick' : 'excused';
        $cursor = Carbon::parse($leave->start_date);
        $end = Carbon::parse($leave->end_date);

        while ($cursor->lte($end)) {
            if (! $cursor->isWeekend()) {
                Attendance::updateOrCreate(
                    ['user_id' => $leave->user_id, 'date' => $cursor->toDateString()],
                    [
                        'status' => $status,
                        'note' => Leave::$leaveTypes[$leave->leave_type].': '.$leave->reason,
                        'company_id' => $leave->company_id,
                    ]
                );
            }
            $cursor->addDay();
        }

        if ($leave->leave_type === 'annual') {
            $leave->user()->withoutGlobalScopes()->first()
                ?->decrement('annual_leave_balance', $leave->days_count);
        }
    }
}
