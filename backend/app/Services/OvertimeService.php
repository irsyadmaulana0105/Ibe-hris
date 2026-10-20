<?php

namespace App\Services;

use App\Models\Overtime;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class OvertimeService
{
    // ── Employee ──────────────────────────────────────────────────────────────

    public function submit(User $user, array $data, ?UploadedFile $attachment = null): Overtime
    {
        $start = Carbon::parse($data['date'].' '.$data['start_time']);
        $end = Carbon::parse($data['date'].' '.$data['end_time']);
        $totalHours = round($start->diffInMinutes($end) / 60, 2);

        if ($attachment) {
            throw ValidationException::withMessages([
                'attachment' => 'Lampiran lembur dinonaktifkan sementara sampai penyimpanan privat tersedia.',
            ]);
        }

        return Overtime::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'date' => $data['date'],
            'check_in_time' => $data['start_time'],
            'check_out_time' => $data['end_time'],
            'total_hours' => $totalHours,
            'reason' => $data['reason'],
            'attachment' => null,
            'status' => 'pending',
        ]);
    }

    public function cancel(User $user, int $overtimeId): void
    {
        Overtime::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->findOrFail($overtimeId)
            ->delete();
    }

    public function listForUser(User $user, int $perPage = 10): LengthAwarePaginator
    {
        return Overtime::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->orderBy('date', 'desc')
            ->paginate($perPage);
    }

    // ── Admin ─────────────────────────────────────────────────────────────────

    public function listForAdmin(User $admin, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Overtime::withoutGlobalScopes()
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
            ->orderBy('date', 'desc');

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['month'])) {
            $d = Carbon::parse($filters['month'].'-01');
            $query->whereYear('date', $d->year)->whereMonth('date', $d->month);
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
        return Overtime::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->count();
    }

    public function summaryForAdmin(User $admin, array $filters = []): array
    {
        $query = Overtime::withoutGlobalScopes()
            ->where('company_id', $admin->company_id);

        if (! empty($filters['month'])) {
            $d = Carbon::parse($filters['month'].'-01');
            $query->whereYear('date', $d->year)->whereMonth('date', $d->month);
        }

        if (! empty($filters['division'])) {
            $query->whereHas('user', fn ($q) => $q->where('division_id', $filters['division']));
        }

        if (! empty($filters['search'])) {
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%')
            );
        }

        $overtimes = $query->get(['status', 'total_hours']);

        return [
            'total_requests' => $overtimes->count(),
            'total_hours' => (float) $overtimes->sum('total_hours'),
            'average_hours' => $overtimes->count() > 0
                ? round((float) $overtimes->avg('total_hours'), 2)
                : 0,
            'status_counts' => [
                'pending' => $overtimes->where('status', 'pending')->count(),
                'approved' => $overtimes->where('status', 'approved')->count(),
                'rejected' => $overtimes->where('status', 'rejected')->count(),
            ],
        ];
    }

    public function approve(User $admin, int $overtimeId, ?string $notes = null): Overtime
    {
        $overtime = $this->approvalQuery($admin)->with('user')->findOrFail($overtimeId);
        $this->ensurePending($overtime->status);
        $overtime->update([
            'status' => 'approved',
            'notes' => $notes ?: null,
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);
        app(AuditLogService::class)->log(
            'overtime.approved',
            $admin,
            'overtime',
            $overtime->id,
            [
                'user_id' => $overtime->user_id,
                'status' => 'approved',
                'notes' => $notes,
            ]
        );
        if ($overtime->user) {
            app(NotificationDispatcher::class)->dispatch('overtime_approved', $overtime->user, [
                'date' => $overtime->date instanceof \DateTimeInterface ? $overtime->date->format('d M Y') : (string) $overtime->date,
                'hours' => $overtime->total_hours,
            ]);
        }

        return $overtime->fresh();
    }

    public function reject(User $admin, int $overtimeId, ?string $notes = null): Overtime
    {
        $overtime = $this->approvalQuery($admin)->with('user')->findOrFail($overtimeId);
        $this->ensurePending($overtime->status);
        $overtime->update([
            'status' => 'rejected',
            'notes' => $notes ?: null,
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);
        app(AuditLogService::class)->log(
            'overtime.rejected',
            $admin,
            'overtime',
            $overtime->id,
            [
                'user_id' => $overtime->user_id,
                'status' => 'rejected',
                'notes' => $notes,
            ]
        );
        if ($overtime->user) {
            app(NotificationDispatcher::class)->dispatch('overtime_rejected', $overtime->user, [
                'date' => $overtime->date instanceof \DateTimeInterface ? $overtime->date->format('d M Y') : (string) $overtime->date,
                'rejection_reason' => $notes ?? 'Tidak disebutkan',
            ]);
        }

        return $overtime->fresh();
    }

    public function detail(User $admin, int $overtimeId): Overtime
    {
        return $this->approvalQuery($admin)
            ->with(['user.division', 'approvedBy'])
            ->findOrFail($overtimeId);
    }

    private function approvalQuery(User $admin)
    {
        return Overtime::withoutGlobalScopes()->where(function ($query) use ($admin) {
            $query->where('company_id', $admin->company_id)
                ->orWhere(function ($legacy) use ($admin) {
                    $legacy->whereNull('company_id')->whereHas('user', fn ($user) => $user->withoutGlobalScopes()
                        ->where('company_id', $admin->company_id));
                });
        });
    }

    private function ensurePending(string $status): void
    {
        if ($status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Lembur hanya dapat diproses saat berstatus pending.']);
        }
    }
}
