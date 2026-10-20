<?php

namespace App\Http\Controllers\Api\V1;

use App\Distribusi\Services\ShipmentService;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShipmentController extends ApiController
{
    public function __construct(private readonly ShipmentService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'inventory.view', 'Akses Distribusi diperlukan.');
        $rows = Shipment::query()
            ->where('company_id', $request->user()->company_id)
            ->when($request->string('status')->toString(), fn ($w, $s) => $w->where('status', $s))
            ->when($request->string('q')->toString(), fn ($w, $q) => $w->where(function ($x) use ($q) {
                $x->where('do_number', 'like', "%{$q}%")
                    ->orWhere('customer_name', 'like', "%{$q}%")
                    ->orWhere('tracking_number', 'like', "%{$q}%");
            }))
            ->with(['items:id,shipment_id,product_name,product_serial,qty', 'warehouse:id,code,name'])
            ->orderByDesc('ship_date')
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->map(fn (Shipment $s) => [
                'id' => $s->id,
                'do_number' => $s->do_number,
                'customer_name' => $s->customer_name,
                'shipping_address' => $s->shipping_address,
                'warehouse' => $s->warehouse ? ['id' => $s->warehouse->id, 'code' => $s->warehouse->code, 'name' => $s->warehouse->name] : null,
                'ship_date' => $s->ship_date?->toDateString(),
                'expected_delivery_date' => $s->expected_delivery_date?->toDateString(),
                'actual_delivery_date' => $s->actual_delivery_date?->toDateString(),
                'carrier' => $s->carrier,
                'tracking_number' => $s->tracking_number,
                'sales_order_id' => $s->sales_order_id,
                'fleet_id' => $s->fleet_id,
                'delivery_route' => $s->delivery_route,
                'shipping_cost' => (float) $s->shipping_cost,
                'status' => $s->status,
                'item_count' => $s->items->count(),
                'created_at' => $s->created_at?->toIso8601String(),
            ]);

        return $this->success(['data' => $rows]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'inventory.view', 'Akses Distribusi diperlukan.');
        $s = Shipment::with(['items', 'warehouse:id,code,name'])
            ->where('company_id', $request->user()->company_id)
            ->findOrFail($id);

        return $this->success([
            'id' => $s->id,
            'do_number' => $s->do_number,
            'customer_id' => $s->customer_id,
            'customer_name' => $s->customer_name,
            'shipping_address' => $s->shipping_address,
            'warehouse' => $s->warehouse ? ['id' => $s->warehouse->id, 'code' => $s->warehouse->code, 'name' => $s->warehouse->name] : null,
            'ship_date' => $s->ship_date?->toDateString(),
            'expected_delivery_date' => $s->expected_delivery_date?->toDateString(),
            'actual_delivery_date' => $s->actual_delivery_date?->toDateString(),
            'carrier' => $s->carrier,
            'tracking_number' => $s->tracking_number,
            'sales_order_id' => $s->sales_order_id,
            'fleet_id' => $s->fleet_id,
            'delivery_route' => $s->delivery_route,
            'shipping_cost' => (float) $s->shipping_cost,
            'status' => $s->status,
            'packed_at' => $s->packed_at?->toIso8601String(),
            'shipped_at' => $s->shipped_at?->toIso8601String(),
            'delivered_at' => $s->delivered_at?->toIso8601String(),
            'received_by_name' => $s->received_by_name,
            'pod_receiver_phone' => $s->pod_receiver_phone,
            'pod_notes' => $s->pod_notes,
            'pod_latitude' => $s->pod_latitude,
            'pod_longitude' => $s->pod_longitude,
            'pod_file_path' => null,
            'pod_signature_path' => null,
            'pod_file_available' => false,
            'pod_signature_available' => false,
            'pod_upload_enabled' => (bool) config('sensitive_file_features.shipment_pod', false),
            'notes' => $s->notes,
            'items' => $s->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product_code' => $i->product_code,
                'product_name' => $i->product_name,
                'product_serial' => $i->product_serial,
                'qty' => (float) $i->qty,
                'unit_of_measure' => $i->unit_of_measure,
                'notes' => $i->notes,
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'inventory.manage', 'Akses pengelolaan Distribusi diperlukan.');
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:fin_customers,id'],
            'customer_name' => ['required', 'string', 'max:200'],
            'shipping_address' => ['required', 'string'],
            'source_warehouse_id' => ['nullable', 'integer', 'exists:inventory_warehouses,id'],
            'ship_date' => ['required', 'date'],
            'expected_delivery_date' => ['nullable', 'date', 'after_or_equal:ship_date'],
            'carrier' => ['nullable', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'invoice_id' => ['nullable', 'integer', 'exists:fin_invoices,id'],
            'sales_order_id' => ['nullable', 'integer', 'exists:sales_orders,id'],
            'fleet_id' => ['nullable', 'integer', 'exists:delivery_fleets,id'],
            'delivery_route' => ['nullable', 'string', 'max:4000'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.product_serial' => ['nullable', 'string', 'max:64'],
            'items.*.product_code' => ['nullable', 'string', 'max:64'],
            'items.*.product_name' => ['required', 'string', 'max:200'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_of_measure' => ['nullable', 'string', 'max:24'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        $s = $this->service->create($request->user(), $data);

        return $this->created([
            'id' => $s->id,
            'do_number' => $s->do_number,
        ], "Shipment {$s->do_number} dibuat.");
    }

    public function transition(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'inventory.manage', 'Akses pengelolaan Distribusi diperlukan.');
        $s = Shipment::where('company_id', $request->user()->company_id)->findOrFail($id);
        $data = $request->validate([
            'to' => ['required', 'in:draft,packed,in_transit,delivered,returned,cancelled'],
            'actual_delivery_date' => ['nullable', 'date'],
            'received_by_name' => ['nullable', 'string', 'max:120'],
            'pod_notes' => ['nullable', 'string', 'max:2000'],
            'pod_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'pod_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'pod_receiver_phone' => ['nullable', 'string', 'max:40'],
            'pod_file' => ['prohibited'],
            'pod_signature' => ['prohibited'],
        ]);
        try {
            $s = $this->service->transition($request->user(), $s, $data['to'], $data);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->success(['status' => $s->status], 'Status diperbarui.');
    }
}
