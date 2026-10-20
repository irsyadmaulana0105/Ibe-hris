<?php

namespace App\Production\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Keuangan\Models\Bill;
use App\Models\PurchaseOrder;
use App\Production\Services\PurchaseOrderService;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends ApiController
{
    public function __construct(
        private readonly PurchaseOrderService $po,
        private readonly AuditLogService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'inventory.view');

        $status = $request->string('status')->toString();
        $supplierId = $request->integer('supplier_id');

        $rows = PurchaseOrder::query()
            ->where('purchase_orders.company_id', $user->company_id)
            ->leftJoin('fin_suppliers as s', 's.id', '=', 'purchase_orders.supplier_id')
            ->leftJoin('inventory_warehouses as w', 'w.id', '=', 'purchase_orders.warehouse_id')
            ->when($status, fn ($x) => $x->where('purchase_orders.status', $status))
            ->when($supplierId, fn ($x) => $x->where('purchase_orders.supplier_id', $supplierId))
            ->select([
                'purchase_orders.*',
                's.name as supplier_name',
                'w.code as warehouse_code', 'w.name as warehouse_name',
            ])
            ->orderByDesc('purchase_orders.date')
            ->orderByDesc('purchase_orders.id')
            ->paginate(30);

        return $this->paginated($rows, $rows->items());
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'inventory.view');

        $po = PurchaseOrder::query()
            ->where('company_id', $user->company_id)
            ->with(['lines.product:id,code,name,unit_of_measure,track_batch,batch_strategy,has_expiry', 'supplier:id,name,code,email', 'warehouse:id,code,name'])
            ->findOrFail($id);

        return $this->success($po);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'inventory.manage');

        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:fin_suppliers,id'],
            'warehouse_id' => ['required', 'integer', 'exists:inventory_warehouses,id'],
            'date' => ['required', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'currency' => ['nullable', 'string', 'size:3'],
            'terms' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $data['company_id'] = $user->company_id;
        $data['po_number'] = $this->po->generatePoNumber($user->company_id);
        $data['status'] = 'draft';
        $data['created_by'] = $user->id;
        $data['tax_rate'] = $data['tax_rate'] ?? 11;
        $data['currency'] = $data['currency'] ?? 'IDR';

        $po = PurchaseOrder::create($data);
        $this->audit->log('po.created', $user, 'purchase_order', (string) $po->id, ['po_number' => $po->po_number]);

        return $this->created($po, "PO {$po->po_number} dibuat (status draft).");
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'inventory.manage');

        $po = PurchaseOrder::query()->where('company_id', $user->company_id)->findOrFail($id);
        if ($po->status !== 'draft') {
            return response()->json(['message' => 'Hanya PO draft yang bisa di-edit header.'], 422);
        }

        $data = $request->validate([
            'supplier_id' => ['sometimes', 'integer', 'exists:fin_suppliers,id'],
            'warehouse_id' => ['sometimes', 'integer', 'exists:inventory_warehouses,id'],
            'date' => ['sometimes', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
            'tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'terms' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);
        $po->update($data);

        return $this->success($po->fresh());
    }

    public function replaceLines(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'inventory.manage');

        $po = PurchaseOrder::query()->where('company_id', $user->company_id)->findOrFail($id);
        $data = $request->validate([
            'lines' => ['required', 'array'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty_ordered' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_of_measure' => ['nullable', 'string', 'max:20'],
            'lines.*.notes' => ['nullable', 'string'],
        ]);

        try {
            $updated = $this->po->replaceLines($po, $data['lines']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->success($updated->load('lines'), 'Lines PO diperbarui.');
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'inventory.manage');

        $po = PurchaseOrder::query()->where('company_id', $user->company_id)->findOrFail($id);
        $this->requireCapability($user, 'inventory.manage', context: [
            'company_id' => $po->company_id, 'resource_type' => 'purchase_order', 'resource_id' => $po->id,
            'amount' => $po->total, 'maker_id' => $po->created_by, 'irreversible' => true,
            'action' => 'approve', 'status' => $po->status,
            'scopes' => ['warehouse_id' => $po->warehouse_id],
        ]);
        try {
            $updated = $this->po->approve($po, $user);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $this->audit->log('po.approved', $user, 'purchase_order', (string) $po->id, []);

        return $this->success($updated, "PO {$po->po_number} approved.");
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'inventory.manage');

        $po = PurchaseOrder::query()->where('company_id', $user->company_id)->findOrFail($id);
        try {
            $updated = $this->po->send($po);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $this->audit->log('po.sent', $user, 'purchase_order', (string) $po->id, []);

        return $this->success($updated, "PO {$po->po_number} sent ke supplier.");
    }

    public function receive(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'inventory.manage');

        $po = PurchaseOrder::query()->where('company_id', $user->company_id)->findOrFail($id);

        $data = $request->validate([
            'receipts' => ['required', 'array', 'min:1'],
            'receipts.*.line_id' => ['required', 'integer'],
            'receipts.*.qty' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            $updated = $this->po->receive($po, $user, $data['receipts']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $this->audit->log('po.received', $user, 'purchase_order', (string) $po->id, [
            'lines' => count($data['receipts']),
        ]);

        return $this->success($updated->load('lines'), 'Goods received + stock IN.');
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'inventory.manage');

        $po = PurchaseOrder::query()->where('company_id', $user->company_id)->findOrFail($id);
        try {
            $updated = $this->po->close($po);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->success($updated, "PO {$po->po_number} closed.");
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'inventory.admin');

        $po = PurchaseOrder::query()->where('company_id', $user->company_id)->findOrFail($id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $updated = $this->po->cancel($po, $data['reason']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->success($updated, "PO {$po->po_number} cancelled.");
    }

    public function matchBill(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireAnyCapability($user, ['inventory.admin', 'finance.module.access']);
        $data = $request->validate(['bill_id' => ['required', 'string', 'max:26']]);
        $po = PurchaseOrder::query()->where('company_id', $user->company_id)->findOrFail($id);
        $bill = Bill::withoutGlobalScopes()
            ->where('company_id', $user->company_id)
            ->where('id', $data['bill_id'])
            ->firstOrFail();
        if ((int) $bill->supplier_id !== (int) $po->supplier_id) {
            return response()->json(['message' => 'Supplier bill tidak sama dengan supplier PO.'], 422);
        }
        try {
            $updated = $this->po->matchToBill($po, (string) $bill->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $this->audit->log('po.bill_matched', $user, 'purchase_order', (string) $po->id, ['bill_id' => $bill->id]);

        return $this->success($updated, 'PO berhasil dicocokkan dengan supplier bill.');
    }
}
