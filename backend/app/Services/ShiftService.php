<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AutoShift;
use App\Models\BusinessTrip;
use App\Models\Shift;
use App\Models\ShiftMapping;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class ShiftService
{
    public function paginateForAdmin(User $admin, array $filters = []): LengthAwarePaginator
    {
        $query = Shift::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->withCount(['autoShifts', 'shiftMappings'])
            ->orderBy('start_time')
            ->orderBy('name');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }

        return $query->paginate((int) ($filters['per_page'] ?? 10));
    }

    public function create(User $admin, array $payload): Shift
    {
        $data = $this->normalizePayload($payload);
        $this->ensureUniqueName($admin, $data['name']);

        $shift = Shift::create([
            'company_id' => $admin->company_id,
            ...$data,
        ]);

        app(AuditLogService::class)->log(
            'master_data.shift.created',
            $admin,
            'shift',
            $shift->id,
            $data
        );

        return $shift->loadCount(['autoShifts', 'shiftMappings']);
    }

    public function update(User $admin, int $id, array $payload): Shift
    {
        $shift = $this->findForAdmin($admin, $id);
        $data = $this->normalizePayload($payload);
        $before = [
            'name' => $shift->name,
            'start_time' => $shift->start_time,
            'end_time' => $shift->end_time,
        ];

        $this->ensureUniqueName($admin, $data['name'], $shift->id);
        $shift->update($data);

        app(AuditLogService::class)->log(
            'master_data.shift.updated',
            $admin,
            'shift',
            $shift->id,
            [
                'before' => $before,
                'after' => $data,
            ]
        );

        return $shift->fresh()->loadCount(['autoShifts', 'shiftMappings']);
    }

    public function delete(User $admin, int $id): void
    {
        $shift = $this->findForAdmin($admin, $id);

        if ($this->isUsed($shift)) {
            throw ValidationException::withMessages([
                'id' => 'Shift masih dipakai oleh data operasional dan tidak dapat dihapus.',
            ]);
        }

        app(AuditLogService::class)->log(
            'master_data.shift.deleted',
            $admin,
            'shift',
            $shift->id,
            [
                'name' => $shift->name,
                'start_time' => $shift->start_time,
                'end_time' => $shift->end_time,
            ]
        );

        $shift->delete();
    }

    public function findForAdmin(User $admin, int $id): Shift
    {
        return Shift::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->findOrFail($id);
    }

    private function normalizePayload(array $payload): array
    {
        return [
            'name' => trim((string) ($payload['name'] ?? '')),
            'start_time' => $payload['start_time'] ?? null,
            'end_time' => $payload['end_time'] ?? null,
        ];
    }

    private function ensureUniqueName(User $admin, string $name, ?int $ignoreId = null): void
    {
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Nama shift wajib diisi.',
            ]);
        }

        $exists = Shift::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'Nama shift sudah digunakan.',
            ]);
        }
    }

    private function isUsed(Shift $shift): bool
    {
        return Attendance::withoutGlobalScopes()->where('shift_id', $shift->id)->exists()
            || BusinessTrip::withoutGlobalScopes()->where('shift_id', $shift->id)->exists()
            || AutoShift::withoutGlobalScopes()->where('shift_id', $shift->id)->exists()
            || ShiftMapping::withoutGlobalScopes()->where('shift_id', $shift->id)->exists();
    }
}
