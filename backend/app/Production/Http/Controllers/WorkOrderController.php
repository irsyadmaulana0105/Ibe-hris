<?php

namespace App\Production\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\WorkOrder;
use App\Production\Services\WorkOrderService;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkOrderController extends ApiController
{
    public function __construct(
        private readonly WorkOrderService $wo,
        private readonly AuditLogService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'production.view');

        $status = $request->string('status')->toString();
        $rows = WorkOrder::query()
            ->where('work_orders.company_id', $user->company_id)
            ->join('products as p', 'p.id', '=', 'work_orders.product_id')
            ->when($status, fn ($x) => $x->where('work_orders.status', $status))
            ->select([
                'work_orders.*',
                'p.code as product_code', 'p.name as product_name',
            ])
            ->orderByDesc('work_orders.created_at')
            ->paginate(30);

        return $this->paginated($rows, $rows->items());
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'production.view');

        $wo = WorkOrder::query()
            ->where('company_id', $user->company_id)
            ->with([
                'product:id,code,name,product_type,unit_of_measure',
                'bom:id,version,name',
                'sourceWarehouse:id,code,name',
                'outputWarehouse:id,code,name',
                'components.component:id,code,name,unit_of_measure',
                'outputs.serialNumber:id,serial_number,asset_status',
            ])
            ->findOrFail($id);

        return $this->success($wo);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'production.manage');

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'qty_planned' => ['required', 'integer', 'min:1'],
            'source_warehouse_id' => ['required', 'integer', 'exists:inventory_warehouses,id'],
            'output_warehouse_id' => ['required', 'integer', 'exists:inventory_warehouses,id'],
            'planned_start_date' => ['nullable', 'date'],
            'planned_complete_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'source_type' => ['nullable', 'string', 'max:50'],
            'source_id' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $wo = $this->wo->createPlan($user, $data);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->audit->log('wo.created', $user, 'work_order', (string) $wo->id, [
            'wo_number' => $wo->wo_number,
            'qty' => $wo->qty_planned,
        ]);

        return $this->created($wo->load(['product', 'bom']), "WO {$wo->wo_number} planned.");
    }

    public function release(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'production.manage');

        $wo = WorkOrder::query()->where('company_id', $user->company_id)->findOrFail($id);
        $this->requireCapability($user, 'production.manage', context: [
            'company_id' => $wo->company_id, 'resource_type' => 'work_order', 'resource_id' => $wo->id,
            'amount' => $wo->total_cost_planned, 'maker_id' => $wo->created_by, 'irreversible' => true,
            'action' => 'release', 'status' => $wo->status,
            'scopes' => ['warehouse_id' => $wo->source_warehouse_id],
        ]);
        try {
            $updated = $this->wo->release($wo, $user);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $this->audit->log('wo.released', $user, 'work_order', (string) $wo->id, []);

        return $this->success($updated, "WO {$wo->wo_number} released. Komponen consumed dari inventory.");
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'production.manage');

        $wo = WorkOrder::query()->where('company_id', $user->company_id)->findOrFail($id);
        $data = $request->validate([
            'spec_values' => ['nullable', 'array'],
        ]);

        try {
            $updated = $this->wo->complete($wo, $user, $data['spec_values'] ?? null);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->audit->log('wo.completed', $user, 'work_order', (string) $wo->id, [
            'produced' => $updated->qty_produced,
        ]);

        return $this->success($updated->load('outputs.serialNumber'), "WO {$wo->wo_number} completed. {$updated->qty_produced} unit + serial numbers generated.");
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'production.admin');

        $wo = WorkOrder::query()->where('company_id', $user->company_id)->findOrFail($id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $updated = $this->wo->cancel($wo, $user, $data['reason']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->success($updated, "WO {$wo->wo_number} cancelled.");
    }
}
