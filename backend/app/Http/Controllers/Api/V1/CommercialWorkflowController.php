<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\CommercialWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommercialWorkflowController extends ApiController
{
    public function __construct(private readonly CommercialWorkflowService $service) {}

    private function company(Request $request): int
    {
        return (int) $request->user()->company_id;
    }

    private function rows(Request $request, string $table, array $capabilities): JsonResponse
    {
        $this->requireAnyCapability($request->user(), $capabilities, 'Anda tidak memiliki akses ke workflow ini.');
        $rows = DB::table($table)->where('company_id', $this->company($request))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')->paginate(min(100, max(10, $request->integer('per_page', 30))));

        return $this->success([
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function salesOrders(Request $request): JsonResponse
    {
        return $this->rows($request, 'sales_orders', ['crm.view', 'finance.module.access']);
    }

    public function purchaseRequests(Request $request): JsonResponse
    {
        return $this->rows($request, 'purchase_requests', ['inventory.view']);
    }

    public function rfqs(Request $request): JsonResponse
    {
        return $this->rows($request, 'procurement_rfqs', ['inventory.view']);
    }

    public function fleets(Request $request): JsonResponse
    {
        return $this->rows($request, 'delivery_fleets', ['inventory.view']);
    }

    public function salesReturns(Request $request): JsonResponse
    {
        return $this->rows($request, 'sales_returns', ['crm.view', 'finance.module.access']);
    }

    public function deliveryReturns(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'inventory.view', 'Anda tidak memiliki akses ke workflow ini.');
        $rows = DB::table('delivery_returns')->where('company_id', $this->company($request))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')->paginate(min(100, max(10, $request->integer('per_page', 30))));

        return $this->success([
            'data' => collect($rows->items())->map(fn ($row) => $this->redactDeliveryReturn($row))->all(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function storeSalesOrder(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'crm.manage', 'Akses pengelolaan Sales diperlukan.');
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer'], 'customer_name' => ['required', 'string', 'max:200'],
            'quotation_id' => ['nullable', 'integer'], 'order_date' => ['required', 'date'], 'requested_delivery_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'], 'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'], 'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sales_person_id' => ['nullable', 'string', 'max:26'], 'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.item_code' => ['nullable', 'string', 'max:64'], 'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.unit_of_measure' => ['nullable', 'string', 'max:24'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'], 'lines.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        return $this->created($this->service->createSalesOrder($request->user(), $data), 'Sales order dibuat.');
    }

    public function transitionSalesOrder(Request $request, int $id, string $action): JsonResponse
    {
        $this->requireCapability($request->user(), 'crm.manage', 'Akses pengelolaan Sales diperlukan.');

        return $this->success($this->service->transitionSalesOrder($request->user(), $id, $action), 'Status sales order diperbarui.');
    }

    public function allocatePayment(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'finance.module.access', 'Akses Finance diperlukan untuk alokasi pembayaran.');
        $data = $request->validate(['payment_date' => ['required', 'date'], 'amount' => ['required', 'numeric', 'gt:0'], 'reference' => ['nullable', 'string', 'max:100'], 'method' => ['nullable', 'string', 'max:40']]);

        return $this->created($this->service->allocatePayment($request->user(), $id, $data), 'Pembayaran dialokasikan.');
    }

    public function storePurchaseRequest(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'inventory.manage', 'Akses Procurement diperlukan.');
        $data = $request->validate(['department' => ['nullable', 'string', 'max:100'], 'request_date' => ['required', 'date'], 'required_date' => ['nullable', 'date'], 'priority' => ['nullable', 'in:low,normal,high,urgent'], 'business_reason' => ['nullable', 'string'], 'items' => ['required', 'array', 'min:1'], 'items.*.description' => ['required', 'string'], 'items.*.quantity' => ['required', 'numeric', 'gt:0'], 'items.*.estimated_unit_cost' => ['nullable', 'numeric', 'min:0']]);

        return $this->created($this->service->createPurchaseRequest($request->user(), $data), 'Purchase request dibuat.');
    }

    public function transitionPurchaseRequest(Request $request, int $id, string $action): JsonResponse
    {
        $this->requireCapability($request->user(), in_array($action, ['approve', 'reject'], true) ? 'inventory.admin' : 'inventory.manage', 'Capability Procurement tidak mencukupi.');

        return $this->success($this->service->transitionPurchaseRequest($request->user(), $id, $action), 'Status purchase request diperbarui.');
    }

    public function storeRfq(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'inventory.manage', 'Akses Procurement diperlukan.');
        $data = $request->validate(['purchase_request_id' => ['nullable', 'integer'], 'issue_date' => ['required', 'date'], 'closing_date' => ['nullable', 'date'], 'items' => ['required', 'array', 'min:1'], 'vendor_quotes' => ['nullable', 'array']]);

        return $this->created($this->service->createRfq($request->user(), $data), 'RFQ dibuat.');
    }

    public function selectRfqVendor(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'inventory.admin', 'Persetujuan vendor memerlukan hak Procurement Admin.');
        $data = $request->validate(['supplier_id' => ['required', 'integer'], 'selection_notes' => ['nullable', 'string']]);

        return $this->success($this->service->selectRfqVendor($request->user(), $id, $data), 'Vendor dipilih.');
    }

    public function storeFleet(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'inventory.manage', 'Akses pengelolaan armada diperlukan.');
        $data = $request->validate(['vehicle_number' => ['required', 'string', 'max:40'], 'vehicle_type' => ['nullable', 'string', 'max:80'], 'driver_name' => ['nullable', 'string', 'max:120'], 'driver_phone' => ['nullable', 'string', 'max:40'], 'status' => ['nullable', 'in:available,assigned,maintenance,inactive'], 'capacity' => ['nullable', 'numeric', 'min:0']]);

        return $this->created($this->service->createFleet($request->user(), $data), 'Armada ditambahkan.');
    }

    public function storeSalesReturn(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'crm.manage', 'Akses pengelolaan Sales diperlukan.');
        $data = $request->validate([
            'sales_order_id' => ['required', 'integer'],
            'return_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->created($this->service->createSalesReturn($request->user(), $data), 'Sales return dibuat.');
    }

    public function transitionSalesReturn(Request $request, int $id, string $action): JsonResponse
    {
        $capability = in_array($action, ['approve', 'reject', 'complete'], true) ? 'finance.module.access' : 'crm.manage';
        $this->requireCapability($request->user(), $capability, 'Hak akses return tidak mencukupi.');

        return $this->success($this->service->transitionSalesReturn($request->user(), $id, $action), 'Status sales return diperbarui.');
    }

    public function storeDeliveryReturn(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'inventory.manage', 'Akses Distribusi diperlukan.');
        $data = $request->validate([
            'shipment_id' => ['required', 'integer'],
            'return_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
            'items' => ['nullable', 'array'],
            'evidence' => ['prohibited'],
        ]);

        $row = $this->service->createDeliveryReturn($request->user(), $data);

        return $this->created($this->redactDeliveryReturn($row), 'Delivery return dibuat.');
    }

    public function transitionDeliveryReturn(Request $request, int $id, string $action): JsonResponse
    {
        $this->requireCapability($request->user(), in_array($action, ['approve', 'reject'], true) ? 'inventory.admin' : 'inventory.manage', 'Hak akses return tidak mencukupi.');

        $row = $this->service->transitionDeliveryReturn($request->user(), $id, $action);

        return $this->success($this->redactDeliveryReturn($row), 'Status delivery return diperbarui.');
    }

    public function convertQuotation(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'crm.manage', 'Akses pengelolaan Sales diperlukan.');

        return $this->created($this->service->convertQuotation($request->user(), $id), 'Quotation dikonversi menjadi Sales Order.');
    }

    public function convertRfq(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'inventory.admin', 'Konversi RFQ memerlukan Procurement Admin.');
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:inventory_warehouses,id'],
            'date' => ['required', 'date'],
            'expected_delivery_date' => ['nullable', 'date', 'after_or_equal:date'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'currency' => ['nullable', 'string', 'size:3'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_of_measure' => ['nullable', 'string', 'max:20'],
            'lines.*.notes' => ['nullable', 'string'],
        ]);

        return $this->created($this->service->convertRfqToPurchaseOrder($request->user(), $id, $data), 'RFQ dikonversi menjadi Purchase Order.');
    }

    /** @return array<string,mixed> */
    private function redactDeliveryReturn(object|array $row): array
    {
        $data = (array) $row;
        unset($data['evidence_path']);
        $data['evidence_available'] = false;
        $data['evidence_upload_enabled'] = (bool) config('sensitive_file_features.delivery_return_evidence', false);

        return $data;
    }
}
