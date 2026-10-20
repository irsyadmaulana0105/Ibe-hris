<?php

namespace App\Production\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\InventoryWarehouse;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseController extends ApiController
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'inventory.view');

        $rows = InventoryWarehouse::query()
            ->where('company_id', $user->company_id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return $this->success($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'inventory.manage');

        $data = $this->validateData($request);
        $data['code'] = strtoupper($data['code']);
        $data['company_id'] = $user->company_id;

        if (! empty($data['is_default'])) {
            // Unset is_default lainnya supaya cuma 1
            InventoryWarehouse::query()
                ->where('company_id', $user->company_id)
                ->update(['is_default' => false]);
        }

        $wh = InventoryWarehouse::create($data);
        $this->audit->log('inventory.warehouse.created', $user, 'warehouse', (string) $wh->id, ['name' => $wh->name]);

        return $this->created($wh, "Warehouse {$wh->code} dibuat.");
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'inventory.manage');

        $wh = InventoryWarehouse::query()->where('company_id', $user->company_id)->findOrFail($id);
        $data = $this->validateData($request, true);

        if (! empty($data['is_default'])) {
            InventoryWarehouse::query()
                ->where('company_id', $user->company_id)
                ->where('id', '!=', $id)
                ->update(['is_default' => false]);
        }

        $wh->update($data);

        return $this->success($wh->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'inventory.admin');

        $wh = InventoryWarehouse::query()->where('company_id', $user->company_id)->findOrFail($id);
        if ($wh->stocks()->where('qty_on_hand', '>', 0)->exists()) {
            return response()->json(['message' => 'Warehouse masih ada stock. Pindahkan dulu sebelum hapus.'], 422);
        }
        $wh->delete();

        return $this->success(message: 'Warehouse dihapus.');
    }

    private function validateData(Request $request, bool $isUpdate = false): array
    {
        $rule = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$rule, 'string', 'max:32'],
            'name' => [$rule, 'string', 'max:150'],
            'type' => ['sometimes', 'in:main,workshop,consignment,in_transit,virtual'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:100'],
            'manager_id' => ['nullable', 'string', 'size:26'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
