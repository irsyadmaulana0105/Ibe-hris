<?php

namespace App\Services;

use App\Models\Education;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class EducationService
{
    public function paginateForAdmin(User $admin, array $filters = []): LengthAwarePaginator
    {
        $query = Education::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->withCount('users')
            ->orderBy('name');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }

        return $query->paginate((int) ($filters['per_page'] ?? 10));
    }

    public function create(User $admin, array $payload): Education
    {
        $name = trim((string) ($payload['name'] ?? ''));

        $this->ensureUniqueName($admin, $name);

        $education = Education::create([
            'company_id' => $admin->company_id,
            'name' => $name,
        ]);

        app(AuditLogService::class)->log(
            'master_data.education.created',
            $admin,
            'education',
            $education->id,
            ['name' => $education->name]
        );

        return $education->loadCount('users');
    }

    public function update(User $admin, int $id, array $payload): Education
    {
        $education = $this->findForAdmin($admin, $id);
        $name = trim((string) ($payload['name'] ?? ''));
        $before = ['name' => $education->name];

        $this->ensureUniqueName($admin, $name, $education->id);

        $education->update(['name' => $name]);

        app(AuditLogService::class)->log(
            'master_data.education.updated',
            $admin,
            'education',
            $education->id,
            [
                'before' => $before,
                'after' => ['name' => $education->name],
            ]
        );

        return $education->fresh()->loadCount('users');
    }

    public function delete(User $admin, int $id): void
    {
        $education = $this->findForAdmin($admin, $id)->loadCount('users');

        if ($education->users_count > 0) {
            throw ValidationException::withMessages([
                'id' => 'Education masih dipakai oleh karyawan dan tidak dapat dihapus.',
            ]);
        }

        app(AuditLogService::class)->log(
            'master_data.education.deleted',
            $admin,
            'education',
            $education->id,
            ['name' => $education->name]
        );

        $education->delete();
    }

    public function findForAdmin(User $admin, int $id): Education
    {
        return Education::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->findOrFail($id);
    }

    private function ensureUniqueName(User $admin, string $name, ?int $ignoreId = null): void
    {
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Nama education wajib diisi.',
            ]);
        }

        $exists = Education::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'Nama education sudah digunakan.',
            ]);
        }
    }
}
