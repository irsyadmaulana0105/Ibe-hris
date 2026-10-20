<?php

namespace App\Distribusi\Services;

use App\Models\InventoryMovement;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Production\Services\BatchService;
use App\Production\Services\StockService;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shipment workflow service.
 *
 * Status transitions:
 *   draft → packed (lock items, deduct stock reserve)
 *   packed → in_transit (actual ship out - stock OUT movement created)
 *   in_transit → delivered (record received_by + delivered_at)
 *   * → cancelled (revert any stock movement)
 */
class ShipmentService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly BatchService $batches,
        private readonly ?AuditLogService $audit = null,
    ) {}

    public function generateDoNumber(int $companyId): string
    {
        $year = date('Y');

        return DB::transaction(function () use ($companyId, $year) {
            $last = Shipment::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('do_number', 'like', "DO-{$year}-%")
                ->orderByDesc('do_number')
                ->lockForUpdate()
                ->value('do_number');
            $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

            return "DO-{$year}-".str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        });
    }

    public function create(User $actor, array $data): Shipment
    {
        return DB::transaction(function () use ($actor, $data) {
            $this->validateTenantReferences($actor, $data);
            $shipment = Shipment::create([
                'company_id' => $actor->company_id,
                'do_number' => $this->generateDoNumber($actor->company_id),
                'customer_id' => $data['customer_id'] ?? null,
                'customer_name' => $data['customer_name'],
                'shipping_address' => $data['shipping_address'],
                'source_warehouse_id' => $data['source_warehouse_id'] ?? null,
                'ship_date' => $data['ship_date'],
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'carrier' => $data['carrier'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'shipping_cost' => (float) ($data['shipping_cost'] ?? 0),
                'status' => 'draft',
                'invoice_id' => $data['invoice_id'] ?? null,
                'sales_order_id' => $data['sales_order_id'] ?? null,
                'fleet_id' => $data['fleet_id'] ?? null,
                'delivery_route' => $data['delivery_route'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            foreach ($data['items'] ?? [] as $item) {
                ShipmentItem::create([
                    'shipment_id' => $shipment->id,
                    'product_id' => $item['product_id'] ?? null,
                    'product_serial' => $item['product_serial'] ?? null,
                    'product_code' => $item['product_code'] ?? null,
                    'product_name' => $item['product_name'] ?? null,
                    'qty' => (float) ($item['qty'] ?? 1),
                    'unit_of_measure' => $item['unit_of_measure'] ?? null,
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            $this->audit?->log('distribution.shipment.created', $actor, 'shipment', $shipment->id, ['do_number' => $shipment->do_number]);

            return $shipment->load('items');
        });
    }

    private function validateTenantReferences(User $actor, array $data): void
    {
        $references = [
            'source_warehouse_id' => 'inventory_warehouses',
            'customer_id' => 'fin_customers',
            'invoice_id' => 'fin_invoices',
            'sales_order_id' => 'sales_orders',
            'fleet_id' => 'delivery_fleets',
        ];

        foreach ($references as $field => $table) {
            if (! empty($data[$field]) && ! DB::table($table)->where('company_id', $actor->company_id)->where('id', $data[$field])->exists()) {
                throw ValidationException::withMessages([$field => 'Referensi tidak ditemukan pada perusahaan aktif.']);
            }
        }

        $productIds = collect($data['items'] ?? [])->pluck('product_id')->filter()->unique()->values();
        if ($productIds->isNotEmpty() && DB::table('products')->where('company_id', $actor->company_id)->whereIn('id', $productIds)->count() !== $productIds->count()) {
            throw ValidationException::withMessages(['items' => 'Semua produk shipment harus berasal dari perusahaan aktif.']);
        }
    }

    public function transition(User $actor, Shipment $shipment, string $to, array $data = []): Shipment
    {
        $allowed = [
            'draft' => ['packed', 'cancelled'],
            'packed' => ['in_transit', 'draft', 'cancelled'],
            'in_transit' => ['delivered', 'returned', 'cancelled'],
            'delivered' => ['returned'],
            'returned' => [],
            'cancelled' => [],
        ];

        return DB::transaction(function () use ($actor, $shipment, $to, $data, $allowed) {
            // Route-bound models may be stale. Serialise every transition for
            // this shipment and make the status check against the locked row.
            $lockedShipment = Shipment::query()->withoutGlobalScopes()
                ->where('company_id', $actor->company_id)
                ->whereKey($shipment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $from = $lockedShipment->status;
            if (! in_array($to, $allowed[$from] ?? [], true)) {
                throw new \RuntimeException("Invalid transition: {$from} → {$to}");
            }

            $items = ShipmentItem::query()
                ->where('shipment_id', $lockedShipment->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $lockedShipment->setRelation('items', $items);

            // Use the same deterministic lock order as StockService. This
            // prevents two multi-product shipments from locking products in
            // opposite item order and deadlocking each other.
            $productIds = $items->pluck('product_id')->filter()->unique()->sort()->values();
            if ($productIds->isNotEmpty()) {
                $lockedProducts = Product::query()->withoutGlobalScopes()
                    ->where('company_id', $actor->company_id)
                    ->whereIn('id', $productIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);
                if ($lockedProducts->count() !== $productIds->count()) {
                    throw new \RuntimeException('Shipment contains a product outside the active tenant.');
                }
            }
            if ($lockedShipment->source_warehouse_id) {
                InventoryWarehouse::query()->withoutGlobalScopes()
                    ->where('company_id', $actor->company_id)
                    ->whereKey($lockedShipment->source_warehouse_id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $update = ['status' => $to];

            if ($to === 'packed') {
                $update['packed_by'] = $actor->id;
                $update['packed_at'] = now();
            } elseif ($to === 'in_transit') {
                $update['shipped_by'] = $actor->id;
                $update['shipped_at'] = now();
                // Deduct stock if linked to a warehouse + product. Shipment must
                // fail closed when inventory cannot be consumed; otherwise the
                // physical delivery and ERP stock ledger silently diverge.
                // Untuk product yang track_batch → route via BatchService (FIFO/FEFO).
                $this->deductShippedStock($actor, $lockedShipment);
            } elseif ($to === 'delivered') {
                $update['delivered_at'] = now();
                $update['actual_delivery_date'] = $data['actual_delivery_date'] ?? now()->toDateString();
                $update['received_by_name'] = $data['received_by_name'] ?? null;
                $update['pod_notes'] = $data['pod_notes'] ?? null;
                $update['pod_latitude'] = $data['pod_latitude'] ?? null;
                $update['pod_longitude'] = $data['pod_longitude'] ?? null;
                $update['pod_file_path'] = $data['pod_file_path'] ?? null;
                $update['pod_signature_path'] = $data['pod_signature_path'] ?? null;
                $update['pod_receiver_phone'] = $data['pod_receiver_phone'] ?? null;
            } elseif (in_array($to, ['cancelled', 'returned'], true)
                && in_array($from, ['in_transit', 'delivered'], true)) {
                // Stock left the warehouse on in_transit. Cancellation or a
                // physical return must reverse that movement exactly once.
                $this->reverseShippedStock($actor, $lockedShipment, $to);
            }

            $lockedShipment->update($update);
            $this->audit?->log('distribution.shipment.status_changed', $actor, 'shipment', $lockedShipment->id, ['from' => $from, 'to' => $to]);

            return $lockedShipment->fresh()->load('items');
        });
    }

    private function deductShippedStock(User $actor, Shipment $shipment): void
    {
        if (! $shipment->source_warehouse_id) {
            return;
        }
        foreach ($shipment->items as $item) {
            if (! $item->product_id) {
                continue;
            }
            $product = Product::query()->withoutGlobalScopes()
                ->where('company_id', $actor->company_id)
                ->findOrFail($item->product_id);

            if ($product->track_batch) {
                $this->batches->consume(
                    $actor,
                    $item->product_id,
                    $shipment->source_warehouse_id,
                    (float) $item->qty,
                    'sale_shipment',
                    [
                        'reference_type' => 'Shipment',
                        'reference_id' => (string) $shipment->id,
                    ],
                    $item->batch_id ?: null,
                );

                continue;
            }

            $this->stock->move(
                $actor,
                $item->product_id,
                $shipment->source_warehouse_id,
                -1 * (float) $item->qty,
                'sale_shipment',
                [
                    'reference_type' => 'Shipment',
                    'reference_id' => (string) $shipment->id,
                    'notes' => "DO {$shipment->do_number}",
                ],
            );
        }
    }

    private function reverseShippedStock(User $actor, Shipment $shipment, string $transition): void
    {
        if (! $shipment->source_warehouse_id) {
            return;
        }
        // Status locking already serialises normal requests. The movement
        // marker is a second fail-closed guard for legacy/inconsistent rows.
        $alreadyReversed = InventoryMovement::query()->withoutGlobalScopes()
            ->where('company_id', $actor->company_id)
            ->where('reference_type', 'ShipmentReversal')
            ->where('reference_id', (string) $shipment->id)
            ->lockForUpdate()
            ->first(['id']);
        if ($alreadyReversed) {
            throw new \RuntimeException("Stock shipment {$shipment->do_number} sudah pernah direversal.");
        }

        $productQuantities = $shipment->items
            ->whereNotNull('product_id')
            ->groupBy('product_id')
            ->map(fn ($items) => (float) $items->sum(fn ($item) => (float) $item->qty));

        foreach ($productQuantities as $productId => $quantity) {
            $product = Product::query()->withoutGlobalScopes()
                ->where('company_id', $actor->company_id)
                ->findOrFail((int) $productId);

            if ($product->track_batch) {
                $originalMovements = InventoryMovement::query()->withoutGlobalScopes()
                    ->where('company_id', $actor->company_id)
                    ->where('product_id', $productId)
                    ->where('warehouse_id', $shipment->source_warehouse_id)
                    ->where('reference_type', 'Shipment')
                    ->where('reference_id', (string) $shipment->id)
                    ->where('reason', 'sale_shipment')
                    ->whereNotNull('batch_id')
                    ->where('qty_delta', '<', 0)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $batchQuantities = $originalMovements
                    ->groupBy('batch_id')
                    ->map(fn ($rows) => (float) $rows->sum(fn ($row) => abs((float) $row->qty_delta)))
                    ->all();

                $restored = array_sum($batchQuantities);
                if ($batchQuantities === [] || abs($restored - $quantity) > 0.0001) {
                    throw new \RuntimeException(
                        "Jejak batch shipment {$shipment->do_number} tidak lengkap; lakukan rekonsiliasi manual."
                    );
                }

                $this->batches->restore(
                    $actor,
                    (int) $productId,
                    $shipment->source_warehouse_id,
                    $batchQuantities,
                    'return_in',
                    [
                        'reference_type' => 'ShipmentReversal',
                        'reference_id' => (string) $shipment->id,
                        'notes' => "{$transition} DO {$shipment->do_number}",
                    ],
                );

                continue;
            }

            $this->stock->move(
                $actor,
                (int) $productId,
                $shipment->source_warehouse_id,
                $quantity,
                'return_in',
                [
                    'reference_type' => 'ShipmentReversal',
                    'reference_id' => (string) $shipment->id,
                    'notes' => "{$transition} DO {$shipment->do_number}",
                ],
            );
        }
    }
}
