<?php

namespace App\Warehouse\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Warehouse\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends ApiController
{
    public function __construct(private readonly InventoryService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'master_data.manage', 'Master data access required.');

        $inventories = $this->service->paginateForAdmin($user, $request->only([
            'search', 'category_id', 'office_location_id', 'job_title_id', 'page', 'per_page',
        ]));

        return $this->paginated($inventories, $inventories->items(), [
            'references' => $this->service->references($user),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'master_data.manage', 'Master data access required.');

        $request->validate([
            'item_code' => ['nullable', 'string', 'max:100'],
            'item_name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer'],
            'stock' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'office_location_id' => ['nullable', 'integer'],
            'job_title_id' => ['nullable', 'integer'],
        ]);

        $inventory = $this->service->create($user, $request->only([
            'item_code', 'item_name', 'category_id', 'stock', 'unit', 'description', 'office_location_id', 'job_title_id',
        ]));

        return $this->created($inventory, 'Item inventory berhasil dibuat.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'master_data.manage', 'Master data access required.');

        $request->validate([
            'item_code' => ['nullable', 'string', 'max:100'],
            'item_name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer'],
            'stock' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'office_location_id' => ['nullable', 'integer'],
            'job_title_id' => ['nullable', 'integer'],
        ]);

        $inventory = $this->service->update($user, $id, $request->only([
            'item_code', 'item_name', 'category_id', 'stock', 'unit', 'description', 'office_location_id', 'job_title_id',
        ]));

        return $this->success($inventory, 'Item inventory berhasil diperbarui.');
    }
}
