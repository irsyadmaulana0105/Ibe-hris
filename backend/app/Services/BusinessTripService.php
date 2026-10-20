<?php

namespace App\Services;

use App\Models\BusinessTrip;
use App\Models\Division;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class BusinessTripService
{
    public function submit(User $user, array $data): BusinessTrip
    {
        return BusinessTrip::create([
            'company_id'    => $user->company_id,
            'user_id'       => $user->id,
            'shift_id'      => $data['shift_id'],
            'date'          => $data['date'],
            'destination'   => $data['destination'],
            'purpose'       => $data['purpose'],
            'check_in_time' => now()->format('H:i:s'),
            'status'        => 'pending',
        ]);
    }

    public function cancel(User $user, int $id): void
    {
        BusinessTrip::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->findOrFail($id)
            ->delete();
    }

    public function listForUser(User $user, int $perPage = 10): LengthAwarePaginator
    {
        return BusinessTrip::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->orderByDesc('date')
            ->paginate($perPage);
    }

    public function shiftsForUser(User $user): Collection
    {
        return Shift::withoutGlobalScopes()
            ->where('company_id', $user->company_id)
            ->get();
    }

    public function listForAdmin(User $admin, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = BusinessTrip::withoutGlobalScopes()
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

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['month'])) {
            $d = Carbon::parse($filters['month'] . '-01');
            $query->whereYear('date', $d->year)->whereMonth('date', $d->month);
        }

        if (!empty($filters['division'])) {
            $query->whereHas('user', fn ($q) => $q->where('division_id', $filters['division']));
        }

        if (!empty($filters['search'])) {
            $query->whereHas('user', fn ($q) =>
                $q->where('name', 'like', '%' . $filters['search'] . '%')
            );
        }

        return $query->paginate($perPage);
    }

    public function approve(User $admin, int $id, ?string $notes = null): BusinessTrip
    {
        $trip = $this->approvalQuery($admin)->findOrFail($id);
        $this->ensurePending($trip->status);
        $trip->update([
            'status'      => 'approved',
            'notes'       => $notes ?: null,
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);

        return $trip->fresh();
    }

    public function reject(User $admin, int $id, ?string $notes = null): BusinessTrip
    {
        $trip = $this->approvalQuery($admin)->findOrFail($id);
        $this->ensurePending($trip->status);
        $trip->update([
            'status'      => 'rejected',
            'notes'       => $notes ?: null,
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);

        return $trip->fresh();
    }

    public function detail(User $admin, int $id): ?BusinessTrip
    {
        return $this->approvalQuery($admin)
            ->with(['user.division', 'shift', 'approvedBy'])
            ->find($id);
    }

    private function approvalQuery(User $admin)
    {
        return BusinessTrip::withoutGlobalScopes()->where(function ($query) use ($admin) {
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
            throw ValidationException::withMessages(['status' => 'Perjalanan dinas hanya dapat diproses saat berstatus pending.']);
        }
    }

    public function divisionsForAdmin(User $admin): Collection
    {
        return Division::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->get();
    }

    public function pendingCount(int|string|null $companyId): int
    {
        return BusinessTrip::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->count();
    }
}
