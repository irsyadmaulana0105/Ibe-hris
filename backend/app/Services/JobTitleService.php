<?php

namespace App\Services;

use App\Models\Division;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class JobTitleService
{
    public function paginateForAdmin(User $admin, array $filters = []): LengthAwarePaginator
    {
        // Filter by company_id langsung (job_titles punya kolom company_id).
        // Old code pakai whereHas('division') yang gagal kalau division_id NULL -
        // padahal banyak jabatan baru di-seed tanpa link ke divisi spesifik.
        $query = JobTitle::withoutGlobalScopes()
            ->where(function ($q) use ($admin) {
                $q->where('company_id', $admin->company_id)->orWhereNull('company_id');
            })
            ->with(['division:id,name'])
            ->withCount('users')
            ->orderBy('name');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }

        if (!empty($filters['division_id'])) {
            $query->where('division_id', $filters['division_id']);
        }

        return $query->paginate((int) ($filters['per_page'] ?? 10));
    }

    public function divisionsForAdmin(User $admin): Collection
    {
        return Division::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function create(User $admin, array $payload): JobTitle
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $divisionId = $this->normalizeDivisionId($admin, $payload['division_id'] ?? null, true);

        $this->ensureUniqueName($admin, $name, $divisionId);

        $jobTitle = JobTitle::create([
            'name' => $name,
            'division_id' => $divisionId,
        ]);

        app(AuditLogService::class)->log(
            'master_data.job_title.created',
            $admin,
            'job_title',
            $jobTitle->id,
            [
                'name' => $jobTitle->name,
                'division_id' => $jobTitle->division_id,
            ]
        );

        return $jobTitle->load(['division:id,name'])->loadCount('users');
    }

    public function update(User $admin, int $id, array $payload): JobTitle
    {
        $jobTitle = $this->findForAdmin($admin, $id);
        $name = trim((string) ($payload['name'] ?? ''));
        // division_id NOT required - banyak jabatan general (Magang, dll) yang
        // gak terkait divisi spesifik. Kalau payload tidak punya key, pertahankan
        // value lama.
        $divisionId = array_key_exists('division_id', $payload)
            ? $this->normalizeDivisionId($admin, $payload['division_id'], false)
            : $jobTitle->division_id;
        $before = [
            'name' => $jobTitle->name,
            'division_id' => $jobTitle->division_id,
        ];

        $this->ensureUniqueName($admin, $name, $divisionId, $jobTitle->id);

        $jobTitle->update([
            'name' => $name,
            'division_id' => $divisionId,
        ]);

        app(AuditLogService::class)->log(
            'master_data.job_title.updated',
            $admin,
            'job_title',
            $jobTitle->id,
            [
                'before' => $before,
                'after' => [
                    'name' => $jobTitle->name,
                    'division_id' => $jobTitle->division_id,
                ],
            ]
        );

        return $jobTitle->fresh()->load(['division:id,name'])->loadCount('users');
    }

    public function delete(User $admin, int $id): void
    {
        $jobTitle = $this->findForAdmin($admin, $id)->loadCount('users');

        if ($jobTitle->users_count > 0) {
            throw ValidationException::withMessages([
                'id' => 'Job title masih dipakai oleh karyawan dan tidak dapat dihapus.',
            ]);
        }

        app(AuditLogService::class)->log(
            'master_data.job_title.deleted',
            $admin,
            'job_title',
            $jobTitle->id,
            [
                'name' => $jobTitle->name,
                'division_id' => $jobTitle->division_id,
            ]
        );

        $jobTitle->delete();
    }

    public function findForAdmin(User $admin, int $id): JobTitle
    {
        // Pakai filter company_id langsung - old code via whereHas('division')
        // fail kalau jabatan belum di-link ke divisi (division_id NULL).
        return JobTitle::withoutGlobalScopes()
            ->where('id', $id)
            ->where(function ($q) use ($admin) {
                $q->where('company_id', $admin->company_id)->orWhereNull('company_id');
            })
            ->firstOrFail();
    }

    private function normalizeDivisionId(User $admin, mixed $divisionId, bool $required = false): ?int
    {
        if ($divisionId === null || $divisionId === '') {
            if ($required) {
                throw ValidationException::withMessages([
                    'division_id' => 'Division wajib dipilih untuk job title.',
                ]);
            }

            return null;
        }

        $division = Division::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->find($divisionId);

        if (!$division) {
            throw ValidationException::withMessages([
                'division_id' => 'Division tidak valid untuk perusahaan aktif.',
            ]);
        }

        return (int) $division->id;
    }

    private function ensureUniqueName(User $admin, string $name, ?int $divisionId, ?int $ignoreId = null): void
    {
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Nama job title wajib diisi.',
            ]);
        }

        $exists = JobTitle::withoutGlobalScopes()
            ->whereHas('division', fn ($builder) => $builder->where('company_id', $admin->company_id))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->where('division_id', $divisionId)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'Nama job title sudah digunakan pada division tersebut.',
            ]);
        }
    }
}
