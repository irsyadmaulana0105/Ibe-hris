<?php

namespace App\Services;

use App\Models\AutoShift;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\ShiftMapping;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ShiftScheduleService
{
    public function references(User $admin): array
    {
        return [
            'shifts' => Shift::withoutGlobalScopes()
                ->where('company_id', $admin->company_id)
                ->orderBy('start_time')
                ->orderBy('name')
                ->get(['id', 'name', 'start_time', 'end_time']),
            'job_titles' => JobTitle::withoutGlobalScopes()
                ->where('company_id', $admin->company_id)
                ->orderBy('name')
                ->get(['id', 'name', 'division_id']),
            'employees' => User::query()
                ->where('company_id', $admin->company_id)
                ->where('group', 'user')
                ->orderBy('name')
                ->get(['id', 'name', 'nip', 'division_id', 'job_title_id']),
        ];
    }

    public function autoShifts(User $admin, array $filters = []): LengthAwarePaginator
    {
        $query = AutoShift::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->with(['jobTitle:id,name,division_id', 'shift:id,name,start_time,end_time'])
            ->orderByDesc('id');

        if (!empty($filters['job_title_id'])) {
            $query->where('job_title_id', $filters['job_title_id']);
        }

        return $query->paginate((int) ($filters['per_page'] ?? 10));
    }

    public function saveAutoShift(User $admin, array $payload): AutoShift
    {
        $jobTitle = $this->findJobTitle($admin, (int) $payload['job_title_id']);
        $shift = $this->findShift($admin, (int) $payload['shift_id']);

        $autoShift = AutoShift::withoutGlobalScopes()->updateOrCreate(
            [
                'company_id' => $admin->company_id,
                'job_title_id' => $jobTitle->id,
            ],
            [
                'shift_id' => $shift->id,
            ]
        );

        app(AuditLogService::class)->log(
            'workforce.auto_shift.saved',
            $admin,
            'auto_shift',
            $autoShift->id,
            [
                'job_title_id' => $jobTitle->id,
                'shift_id' => $shift->id,
            ]
        );

        return $autoShift->load(['jobTitle:id,name,division_id', 'shift:id,name,start_time,end_time']);
    }

    public function deleteAutoShift(User $admin, int $id): void
    {
        $autoShift = AutoShift::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->findOrFail($id);

        app(AuditLogService::class)->log(
            'workforce.auto_shift.deleted',
            $admin,
            'auto_shift',
            $autoShift->id,
            [
                'job_title_id' => $autoShift->job_title_id,
                'shift_id' => $autoShift->shift_id,
            ]
        );

        $autoShift->delete();
    }

    public function mappings(User $admin, array $filters = []): LengthAwarePaginator
    {
        $query = ShiftMapping::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->with(['user:id,name,nip,division_id,job_title_id', 'shift:id,name,start_time,end_time'])
            ->orderByDesc('date')
            ->orderByDesc('id');

        if (!empty($filters['date'])) {
            $query->whereDate('date', Carbon::parse($filters['date'])->toDateString());
        } elseif (!empty($filters['month'])) {
            $month = Carbon::parse($filters['month']);
            $query->whereMonth('date', $month->month)->whereYear('date', $month->year);
        }

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        return $query->paginate((int) ($filters['per_page'] ?? 10));
    }

    public function saveMapping(User $admin, array $payload): ShiftMapping
    {
        $employee = $this->findEmployee($admin, (string) $payload['user_id']);
        $shift = $this->findShift($admin, (int) $payload['shift_id']);
        $date = Carbon::parse($payload['date'])->toDateString();

        $mapping = ShiftMapping::withoutGlobalScopes()->updateOrCreate(
            [
                'company_id' => $admin->company_id,
                'user_id' => $employee->id,
                'date' => $date,
            ],
            [
                'shift_id' => $shift->id,
            ]
        );

        app(AuditLogService::class)->log(
            'workforce.shift_mapping.saved',
            $admin,
            'shift_mapping',
            $mapping->id,
            [
                'user_id' => $employee->id,
                'shift_id' => $shift->id,
                'date' => $date,
            ]
        );

        return $mapping->load(['user:id,name,nip,division_id,job_title_id', 'shift:id,name,start_time,end_time']);
    }

    public function deleteMapping(User $admin, int $id): void
    {
        $mapping = ShiftMapping::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->findOrFail($id);

        app(AuditLogService::class)->log(
            'workforce.shift_mapping.deleted',
            $admin,
            'shift_mapping',
            $mapping->id,
            [
                'user_id' => $mapping->user_id,
                'shift_id' => $mapping->shift_id,
                'date' => $mapping->date?->toDateString(),
            ]
        );

        $mapping->delete();
    }

    private function findShift(User $admin, int $id): Shift
    {
        return Shift::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->findOrFail($id);
    }

    private function findJobTitle(User $admin, int $id): JobTitle
    {
        return JobTitle::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->findOrFail($id);
    }

    private function findEmployee(User $admin, string $id): User
    {
        $employee = User::where('company_id', $admin->company_id)
            ->where('group', 'user')
            ->find($id);

        if (!$employee) {
            throw ValidationException::withMessages([
                'user_id' => 'Karyawan tidak ditemukan untuk perusahaan ini.',
            ]);
        }

        return $employee;
    }
}
