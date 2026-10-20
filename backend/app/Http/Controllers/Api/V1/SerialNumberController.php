<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ProductSerialNumber;
use App\Services\AuditLogService;
use App\Produksi\Services\ProductionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Generate + list serial number Produk Robot IBE. Akses: role 'produksi'
 * atau superadmin.
 */
class SerialNumberController extends ApiController
{
    public function __construct(
        private readonly ProductionService $production,
        private readonly AuditLogService $audit,
    ) {}

    /** GET /api/v1/admin/serial-numbers */
    public function index(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'serial_number.manage', 'Akses production role required.');

        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(100, max(10, (int) $request->integer('per_page', 20)));
        $q = $request->string('q')->toString();
        $productCode = $request->string('product_code')->toString();
        $status = $request->string('status')->toString();

        $paginator = ProductSerialNumber::query()
            ->where('company_id', $request->user()->company_id)
            ->when($q, fn ($w) => $w->where(function ($x) use ($q) {
                $x->where('serial_number', 'like', "%{$q}%")
                  ->orWhere('customer_name', 'like', "%{$q}%")
                  ->orWhere('product_name', 'like', "%{$q}%");
            }))
            ->when($productCode, fn ($w) => $w->where('product_code', strtoupper($productCode)))
            ->when($status, fn ($w) => $w->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $rows = collect($paginator->items())->map(fn (ProductSerialNumber $sn) => [
            'id'              => $sn->id,
            'product_id'      => $sn->product_id,
            'serial_number'   => $sn->serial_number,
            'product_code'    => $sn->product_code,
            'product_name'    => $sn->product_name,
            'product_variant' => $sn->product_variant,
            'spec_values'     => $sn->spec_values,
            'customer_name'   => $sn->customer_name,
            'customer_email'  => $sn->customer_email,
            'customer_phone'  => $sn->customer_phone,
            'issued_at'       => $sn->issued_at?->toDateString(),
            'manufactured_at' => $sn->manufactured_at?->toDateString(),
            'warranty_until'  => $sn->warranty_until?->toDateString(),
            'status'          => $sn->status,
            'asset_status'    => $sn->asset_status,
            'asset_location'  => $sn->asset_location,
            'purchase_value'  => $sn->purchase_value !== null ? (float) $sn->purchase_value : null,
            'sale_value'      => $sn->sale_value !== null ? (float) $sn->sale_value : null,
            'fin_customer_id' => $sn->fin_customer_id,
            'notes'           => $sn->notes,
            'created_at'      => $sn->created_at?->toIso8601String(),
        ])->values();

        return $this->paginated($paginator, $rows);
    }

    /** POST /api/v1/admin/serial-numbers - generate + assign */
    public function store(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'serial_number.manage', 'Akses production role required.');

        $data = $request->validate([
            'product_id'      => ['nullable', 'integer', 'exists:products,id'],
            'product_code'    => ['required', 'string', 'max:32'],
            'product_name'    => ['required', 'string', 'max:120'],
            'product_variant' => ['nullable', 'string', 'max:80'],
            'spec_values'     => ['nullable', 'array'],
            'customer_name'   => ['nullable', 'string', 'max:200'],
            'customer_email'  => ['nullable', 'email', 'max:200'],
            'customer_phone'  => ['nullable', 'string', 'max:32'],
            'issued_at'       => ['nullable', 'date'],
            'manufactured_at' => ['nullable', 'date'],
            'warranty_until'  => ['nullable', 'date', 'after_or_equal:issued_at'],
            'asset_status'    => ['nullable', 'in:in_warehouse,sold,leased,in_service,retired,lost'],
            'asset_location'  => ['nullable', 'string', 'max:200'],
            'purchase_value'  => ['nullable', 'numeric', 'min:0'],
            'notes'           => ['nullable', 'string', 'max:1000'],
            'quantity'        => ['nullable', 'integer', 'min:1', 'max:100'], // bulk generate
        ]);

        $qty = (int) ($data['quantity'] ?? 1);
        unset($data['quantity']);
        $generated = [];

        for ($i = 0; $i < $qty; $i++) {
            $sn = $this->production->generateSerialNumber((int) $admin->company_id, $data['product_code']);
            $row = ProductSerialNumber::create(array_merge($data, [
                'serial_number'        => $sn,
                'product_code'         => strtoupper($data['product_code']),
                'status'               => 'active',
                'generated_by_user_id' => $admin->id,
            ]));
            $generated[] = $row->serial_number;
        }

        $this->audit->log(
            'serial_number.generated',
            $admin, 'serial_number', null,
            ['product_code' => strtoupper($data['product_code']), 'count' => $qty, 'serials' => $generated],
            $request,
        );

        return $this->created([
            'count'   => $qty,
            'serials' => $generated,
        ], "{$qty} serial number berhasil di-generate.");
    }

    /** PUT /api/v1/admin/serial-numbers/{id} */
    public function update(Request $request, string $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'serial_number.manage', 'Akses production role required.');

        $sn = ProductSerialNumber::query()
            ->where('company_id', $admin->company_id)
            ->findOrFail($id);

        $data = $request->validate([
            'spec_values'     => ['nullable', 'array'],
            'customer_name'   => ['nullable', 'string', 'max:200'],
            'customer_email'  => ['nullable', 'email', 'max:200'],
            'customer_phone'  => ['nullable', 'string', 'max:32'],
            'issued_at'       => ['nullable', 'date'],
            'manufactured_at' => ['nullable', 'date'],
            'warranty_until'  => ['nullable', 'date'],
            'status'          => ['nullable', 'in:active,recalled,retired'],
            'asset_status'    => ['nullable', 'in:in_warehouse,sold,leased,in_service,retired,lost'],
            'asset_location'  => ['nullable', 'string', 'max:200'],
            'purchase_value'  => ['nullable', 'numeric', 'min:0'],
            'sale_value'      => ['nullable', 'numeric', 'min:0'],
            'fin_customer_id' => ['nullable', 'integer'],
            'notes'           => ['nullable', 'string', 'max:1000'],
        ]);
        $sn->update($data);

        $this->audit->log(
            'serial_number.updated',
            $admin, 'serial_number', $sn->id,
            ['changes' => $data],
            $request,
        );

        return $this->success(['serial_number' => $sn->serial_number], 'Data SN diperbarui.');
    }

    /** GET /api/v1/admin/serial-numbers/lookup/{serial} - quick lookup for complaint form */
    public function lookup(Request $request, string $serial): JsonResponse
    {
        $this->requireCapability($request->user(), 'serial_number.manage', 'Akses production role required.');

        $sn = $this->production->lookupSerial($serial);
        if (!$sn) {
            return response()->json(['message' => 'Serial number tidak ditemukan.'], 404);
        }

        return $this->success([
            'serial_number'  => $sn->serial_number,
            'product_name'   => $sn->product_name,
            'product_variant'=> $sn->product_variant,
            'product_code'   => $sn->product_code,
            'customer_name'  => $sn->customer_name,
            'warranty_until' => $sn->warranty_until?->toDateString(),
            'status'         => $sn->status,
        ]);
    }
}
