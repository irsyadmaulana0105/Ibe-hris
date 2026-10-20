<?php

namespace App\Services;

use App\Models\Division;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class DivisionService
{
    public function paginateForAdmin(User $admin, array $filters = []): LengthAwarePaginator
    {
        $query = Division::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->withCount('users')
            ->orderBy('name');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }

        return $query->paginate((int) ($filters['per_page'] ?? 10));
    }

    public function create(User $admin, array $payload): Division
    {
        $name = trim((string) ($payload['name'] ?? ''));

        $this->ensureUniqueName($admin, $name);

        $division = Division::create([
            'company_id'       => $admin->company_id,
            'name'             => $name,
            'default_shift_id' => $payload['default_shift_id'] ?? null,
        ]);

        app(AuditLogService::class)->log(
            'master_data.division.created',
            $admin,
            'division',
            $division->id,
            ['name' => $division->name, 'default_shift_id' => $division->default_shift_id]
        );

        return $division->loadCount('users');
    }

    public function update(User $admin, int $id, array $payload): Division
    {
        $division = $this->findForAdmin($admin, $id);
        $name = trim((string) ($payload['name'] ?? ''));
        $before = ['name' => $division->name, 'default_shift_id' => $division->default_shift_id];

        $this->ensureUniqueName($admin, $name, $division->id);

        $division->update([
            'name'             => $name,
            'default_shift_id' => array_key_exists('default_shift_id', $payload) ? $payload['default_shift_id'] : $division->default_shift_id,
        ]);

        app(AuditLogService::class)->log(
            'master_data.division.updated',
            $admin,
            'division',
            $division->id,
            [
                'before' => $before,
                'after'  => ['name' => $division->name, 'default_shift_id' => $division->default_shift_id],
            ]
        );

        return $division->fresh()->loadCount('users');
    }

    public function delete(User $admin, int $id): void
    {
        $division = $this->findForAdmin($admin, $id)->loadCount('users');

        if ($division->users_count > 0) {
            throw ValidationException::withMessages([
                'id' => 'Division masih dipakai oleh karyawan dan tidak dapat dihapus.',
            ]);
        }

        app(AuditLogService::class)->log(
            'master_data.division.deleted',
            $admin,
            'division',
            $division->id,
            ['name' => $division->name]
        );

        $division->delete();
    }

    public function findForAdmin(User $admin, int $id): Division
    {
        return Division::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->findOrFail($id);
    }

    private function ensureUniqueName(User $admin, string $name, ?int $ignoreId = null): void
    {
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Nama division wajib diisi.',
            ]);
        }

        $exists = Division::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'Nama division sudah digunakan.',
            ]);
        }
    }
}
