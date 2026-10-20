<?php

namespace App\Warehouse\Services;

use App\Models\JobTitle;
use App\Models\OfficeLocation;
use App\Models\User;
use App\Services\AuditLogService;
use App\Warehouse\Models\Inventory;
use App\Warehouse\Models\InventoryCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function paginateForAdmin(User $admin, array $filters = []): LengthAwarePaginator
    {
        $query = Inventory::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->with(['category:id,name', 'officeLocation:id,name', 'jobTitle:id,name,division_id'])
            ->orderBy('item_name')
            ->orderBy('id');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($inner) use ($search) {
                $inner->where('item_name', 'like', "%{$search}%")
                    ->orWhere('item_code', 'like', "%{$search}%")
                    ->orWhere('unit', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        if (! empty($filters['office_location_id'])) {
            $query->where('office_location_id', (int) $filters['office_location_id']);
        }

        if (! empty($filters['job_title_id'])) {
            $query->where('job_title_id', (int) $filters['job_title_id']);
        }

        return $query->paginate((int) ($filters['per_page'] ?? 10));
    }

    public function references(User $admin): array
    {
        return [
            'categories' => $this->categories($admin),
            'office_locations' => $this->officeLocations($admin),
            'job_titles' => $this->jobTitles($admin),
        ];
    }

    public function create(User $admin, array $payload): Inventory
    {
        $data = $this->normalizePayload($admin, $payload);

        $inventory = Inventory::create([
            'company_id' => $admin->company_id,
            ...$data,
        ]);

        app(AuditLogService::class)->log(
            'inventory.item.created',
            $admin,
            'inventory',
            $inventory->id,
            $data
        );

        return $this->findForAdmin($admin, $inventory->id);
    }

    public function update(User $admin, int $id, array $payload): Inventory
    {
        $inventory = $this->findForAdmin($admin, $id);
        $before = $inventory->only([
            'item_code', 'item_name', 'category_id', 'stock', 'unit', 'description', 'office_location_id', 'job_title_id',
        ]);
        $data = $this->normalizePayload($admin, $payload, $inventory->id);

        $inventory->update($data);

        app(AuditLogService::class)->log(
            'inventory.item.updated',
            $admin,
            'inventory',
            $inventory->id,
            [
                'before' => $before,
                'after' => $data,
            ]
        );

        return $this->findForAdmin($admin, $inventory->id);
    }

    public function findForAdmin(User $admin, int $id): Inventory
    {
        return Inventory::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->with(['category:id,name', 'officeLocation:id,name', 'jobTitle:id,name,division_id'])
            ->findOrFail($id);
    }

    private function normalizePayload(User $admin, array $payload, ?int $ignoreId = null): array
    {
        $data = [
            'item_code' => $this->nullableString($payload['item_code'] ?? null),
            'item_name' => trim((string) ($payload['item_name'] ?? '')),
            'category_id' => $this->nullableInt($payload['category_id'] ?? null),
            'stock' => isset($payload['stock']) ? (float) $payload['stock'] : 0,
            'unit' => $this->nullableString($payload['unit'] ?? null),
            'description' => $this->nullableString($payload['description'] ?? null),
            'office_location_id' => $this->nullableInt($payload['office_location_id'] ?? null),
            'job_title_id' => $this->nullableInt($payload['job_title_id'] ?? null),
        ];

        if ($data['item_name'] === '') {
            throw ValidationException::withMessages([
                'item_name' => 'Nama item wajib diisi.',
            ]);
        }

        if ($data['item_code']) {
            $exists = Inventory::withoutGlobalScopes()
                ->where('company_id', $admin->company_id)
                ->whereRaw('LOWER(item_code) = ?', [mb_strtolower($data['item_code'])])
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'item_code' => 'Kode item sudah digunakan.',
                ]);
            }
        }

        if ($data['category_id']) {
            $categoryExists = InventoryCategory::withoutGlobalScopes()
                ->where('company_id', $admin->company_id)
                ->where('id', $data['category_id'])
                ->exists();

            if (! $categoryExists) {
                throw ValidationException::withMessages([
                    'category_id' => 'Kategori inventory tidak valid.',
                ]);
            }
        }

        if ($data['office_location_id']) {
            $officeExists = OfficeLocation::withoutGlobalScopes()
                ->where('company_id', $admin->company_id)
                ->where('id', $data['office_location_id'])
                ->exists();

            if (! $officeExists) {
                throw ValidationException::withMessages([
                    'office_location_id' => 'Office location tidak valid.',
                ]);
            }
        }

        if ($data['job_title_id']) {
            $jobTitleExists = JobTitle::withoutGlobalScopes()
                ->where('company_id', $admin->company_id)
                ->where('id', $data['job_title_id'])
                ->exists();

            if (! $jobTitleExists) {
                throw ValidationException::withMessages([
                    'job_title_id' => 'Job title tidak valid.',
                ]);
            }
        }

        return $data;
    }

    private function categories(User $admin): Collection
    {
        return InventoryCategory::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function officeLocations(User $admin): Collection
    {
        return OfficeLocation::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function jobTitles(User $admin): Collection
    {
        return JobTitle::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->orderBy('name')
            ->get(['id', 'name', 'division_id']);
    }

    private function nullableString(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
