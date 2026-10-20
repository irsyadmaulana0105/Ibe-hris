<?php

namespace App\Production\Services;

use App\Models\InventoryBatch;
use App\Models\InventoryBatchStock;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Lot/batch tracking - operations untuk product yang track_batch = true.
 *
 * Adopsi opt-in: kalau Product.track_batch = false, semua operasi tetap
 * jalan via flow lama (InventoryStock + InventoryMovement langsung).
 * Kalau track_batch = true:
 *   - receive() bikin/tambah batch + qty di batch_stock
 *   - consume() pakai FIFO/FEFO order untuk pilih batch yg di-consume
 *
 * FIFO  = First In, First Out - pilih batch dengan received_at terlama
 * FEFO  = First Expiry, First Out - pilih batch dengan expires_at terdekat
 *         (skip batch tanpa expires_at sampai semua expiry-yang-ada habis)
 */
class BatchService
{
    /**
     * Restore exact quantities into existing batches (shipment return/cancel).
     * The caller supplies the immutable outbound movement breakdown so a
     * multi-batch FIFO/FEFO consume is reversed to the original lots.
     *
     * @param  array<int|string, float|int|string>  $batchQuantities  batch_id => qty
     * @return array<int, InventoryMovement>
     */
    public function restore(
        User $actor,
        int $productId,
        int $warehouseId,
        array $batchQuantities,
        string $reason = 'return_in',
        array $refMeta = [],
    ): array {
        $batchQuantities = collect($batchQuantities)
            ->mapWithKeys(fn ($qty, $batchId) => [(int) $batchId => (float) $qty])
            ->filter(fn (float $qty) => $qty > 0)
            ->sortKeys()
            ->all();
        if ($batchQuantities === []) {
            throw new \InvalidArgumentException('Batch restore quantities cannot be empty.');
        }

        return DB::transaction(function () use ($actor, $productId, $warehouseId, $batchQuantities, $reason, $refMeta) {
            $product = Product::query()->withoutGlobalScopes()
                ->where('company_id', $actor->company_id)
                ->whereKey($productId)
                ->lockForUpdate()
                ->firstOrFail();
            if (! $product->track_batch) {
                throw new \RuntimeException("Product {$product->code} tidak track batch.");
            }

            InventoryWarehouse::query()->withoutGlobalScopes()
                ->where('company_id', $actor->company_id)
                ->where('is_active', true)
                ->whereKey($warehouseId)
                ->lockForUpdate()
                ->firstOrFail();

            $batches = InventoryBatch::query()->withoutGlobalScopes()
                ->where('company_id', $actor->company_id)
                ->where('product_id', $productId)
                ->whereIn('id', array_keys($batchQuantities))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($batches->count() !== count($batchQuantities)) {
                throw new \RuntimeException('Batch restore contains a batch outside the active tenant/product.');
            }

            $aggregate = InventoryStock::query()->withoutGlobalScopes()
                ->where('company_id', $actor->company_id)
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();
            if (! $aggregate) {
                $aggregate = InventoryStock::create([
                    'company_id' => $actor->company_id,
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'qty_on_hand' => 0,
                    'qty_reserved' => 0,
                ]);
            }

            $runningQty = (float) $aggregate->qty_on_hand;
            $movements = [];
            foreach ($batchQuantities as $batchId => $quantity) {
                $batchStock = InventoryBatchStock::query()->withoutGlobalScopes()
                    ->where('company_id', $actor->company_id)
                    ->where('batch_id', $batchId)
                    ->where('warehouse_id', $warehouseId)
                    ->lockForUpdate()
                    ->first();
                if (! $batchStock) {
                    $batchStock = InventoryBatchStock::create([
                        'company_id' => $actor->company_id,
                        'batch_id' => $batchId,
                        'warehouse_id' => $warehouseId,
                        'qty_on_hand' => 0,
                        'qty_reserved' => 0,
                    ]);
                }

                $batchStock->update([
                    'qty_on_hand' => (float) $batchStock->qty_on_hand + $quantity,
                    'last_movement_at' => now(),
                ]);

                $qtyBefore = $runningQty;
                $runningQty += $quantity;
                $movements[] = InventoryMovement::create([
                    'company_id' => $actor->company_id,
                    'product_id' => $productId,
                    'batch_id' => $batchId,
                    'warehouse_id' => $warehouseId,
                    'qty_delta' => $quantity,
                    'qty_before' => $qtyBefore,
                    'qty_after' => $runningQty,
                    'unit_cost' => $batches[$batchId]->unit_cost,
                    'reason' => $reason,
                    'reference_type' => $refMeta['reference_type'] ?? null,
                    'reference_id' => $refMeta['reference_id'] ?? null,
                    'notes' => $refMeta['notes'] ?? "Restore batch {$batches[$batchId]->batch_code} qty +{$quantity}",
                    'performed_by' => $actor->id,
                ]);
            }

            $this->syncAggregateStock($productId, $warehouseId);

            return $movements;
        });
    }

    /**
     * Receive stock dengan batch info - biasanya dipanggil dari PO receive.
     *
     * Return: InventoryBatch yang dipakai (existing kalau batch_code sama,
     * baru kalau belum ada).
     */
    public function receive(
        User $actor,
        int $productId,
        int $warehouseId,
        float $qty,
        string $batchCode,
        array $batchMeta = [], // received_at, expires_at, supplier_lot, unit_cost, source_po_id
    ): InventoryBatch {
        if ($qty <= 0) {
            throw new \InvalidArgumentException('Qty harus > 0');
        }

        return DB::transaction(function () use ($actor, $productId, $warehouseId, $qty, $batchCode, $batchMeta) {
            $product = Product::findOrFail($productId);
            if (! $product->track_batch) {
                throw new \RuntimeException("Product {$product->code} tidak track batch. Pakai StockService::move biasa.");
            }

            // Idempotent: lookup existing batch dengan code sama
            $batch = InventoryBatch::firstOrCreate(
                [
                    'company_id' => $actor->company_id,
                    'product_id' => $productId,
                    'batch_code' => $batchCode,
                ],
                [
                    'received_at' => $batchMeta['received_at'] ?? now(),
                    'expires_at' => $batchMeta['expires_at'] ?? null,
                    'manufactured_at' => $batchMeta['manufactured_at'] ?? null,
                    'supplier_lot' => $batchMeta['supplier_lot'] ?? null,
                    'source_po_id' => $batchMeta['source_po_id'] ?? null,
                    'unit_cost' => $batchMeta['unit_cost'] ?? $product->unit_cost,
                    'is_quarantined' => $batchMeta['is_quarantined'] ?? false,
                ],
            );

            // Aggregate stock snapshot - used for qty_before/qty_after audit
            $qtyBefore = (float) InventoryStock::where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->value('qty_on_hand') ?: 0.0;

            // Add to batch_stock
            $bs = InventoryBatchStock::firstOrNew([
                'batch_id' => $batch->id,
                'warehouse_id' => $warehouseId,
            ]);
            $bs->company_id = $actor->company_id;
            $bs->qty_on_hand = (float) ($bs->qty_on_hand ?? 0) + $qty;
            $bs->last_movement_at = now();
            $bs->save();

            // Also update aggregate inventory_stock
            $this->syncAggregateStock($productId, $warehouseId);

            // Movement log
            InventoryMovement::create([
                'company_id' => $actor->company_id,
                'product_id' => $productId,
                'batch_id' => $batch->id,
                'warehouse_id' => $warehouseId,
                'qty_delta' => $qty,
                'qty_before' => $qtyBefore,
                'qty_after' => $qtyBefore + $qty,
                'unit_cost' => $batch->unit_cost,
                'reason' => 'purchase_receipt',
                'reference_type' => $batchMeta['reference_type'] ?? null,
                'reference_id' => $batchMeta['reference_id'] ?? null,
                'notes' => "Receive batch {$batchCode} qty +{$qty}",
                'performed_by' => $actor->id,
            ]);

            return $batch;
        });
    }

    /**
     * Consume stock pakai batch strategy. Pilih batch order by FIFO/FEFO,
     * potong dari batch_stock multi-batch kalau perlu sampai qty kepenuhan.
     *
     * Return: list of [batch_id, batch_code, qty_consumed] - buat audit trail
     * dan pricing (batch unit_cost beda-beda).
     *
     * Kalau product gak track_batch, throw - caller harus pakai StockService::move biasa.
     */
    public function consume(
        User $actor,
        int $productId,
        int $warehouseId,
        float $qty,
        string $reason = 'adjustment',
        array $refMeta = [],
        ?int $preferredBatchId = null,
    ): array {
        if ($qty <= 0) {
            throw new \InvalidArgumentException('Qty harus > 0');
        }

        return DB::transaction(function () use ($actor, $productId, $warehouseId, $qty, $reason, $refMeta, $preferredBatchId) {
            $product = Product::findOrFail($productId);
            if (! $product->track_batch) {
                throw new \RuntimeException("Product {$product->code} tidak track batch.");
            }

            $strategy = $product->batch_strategy ?? 'fifo';
            $query = InventoryBatchStock::query()
                ->where('warehouse_id', $warehouseId)
                ->where('qty_on_hand', '>', 0)
                ->whereExists(function ($q) use ($productId) {
                    $q->select(DB::raw(1))->from('inventory_batches')
                        ->whereColumn('inventory_batches.id', 'inventory_batch_stock.batch_id')
                        ->where('inventory_batches.product_id', $productId)
                        ->where('inventory_batches.is_quarantined', false);
                })
                ->join('inventory_batches', 'inventory_batches.id', '=', 'inventory_batch_stock.batch_id')
                ->select('inventory_batch_stock.*', 'inventory_batches.batch_code', 'inventory_batches.unit_cost as batch_cost');

            // Override pick - kalau caller spesifik request batch tertentu
            // (mis. shipment_item.batch_id), prioritas batch itu dulu.
            if ($preferredBatchId) {
                $query->orderByRaw('CASE WHEN inventory_batches.id = ? THEN 0 ELSE 1 END', [$preferredBatchId]);
            }

            if ($strategy === 'fefo') {
                // Earliest expires_at first; null expires_at last
                $query->orderByRaw('CASE WHEN inventory_batches.expires_at IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('inventory_batches.expires_at')
                    ->orderBy('inventory_batches.received_at');
            } else {
                // Default FIFO - earliest received_at first
                $query->orderBy('inventory_batches.received_at');
            }

            $batches = $query->get();

            $remaining = $qty;
            $consumed = [];
            foreach ($batches as $b) {
                if ($remaining <= 0) {
                    break;
                }
                $take = min($remaining, (float) $b->qty_on_hand);
                if ($take <= 0) {
                    continue;
                }

                // Aggregate snapshot for this movement
                $qtyBefore = (float) InventoryStock::where('product_id', $productId)
                    ->where('warehouse_id', $warehouseId)
                    ->value('qty_on_hand') ?: 0.0;

                // Update batch_stock
                InventoryBatchStock::where('id', $b->id)->update([
                    'qty_on_hand' => DB::raw("qty_on_hand - {$take}"),
                    'last_movement_at' => now(),
                ]);

                InventoryMovement::create([
                    'company_id' => $actor->company_id,
                    'product_id' => $productId,
                    'batch_id' => $b->batch_id,
                    'warehouse_id' => $warehouseId,
                    'qty_delta' => -$take,
                    'qty_before' => $qtyBefore,
                    'qty_after' => $qtyBefore - $take,
                    'unit_cost' => $b->batch_cost,
                    'reason' => $reason,
                    'reference_type' => $refMeta['reference_type'] ?? null,
                    'reference_id' => $refMeta['reference_id'] ?? null,
                    'notes' => "Consume batch {$b->batch_code} qty -{$take}",
                    'performed_by' => $actor->id,
                ]);

                $consumed[] = [
                    'batch_id' => $b->batch_id,
                    'batch_code' => $b->batch_code,
                    'qty_consumed' => $take,
                    'unit_cost' => (float) $b->batch_cost,
                    'cost_subtotal' => $take * (float) $b->batch_cost,
                ];
                $remaining -= $take;
            }

            if ($remaining > 0) {
                throw new \RuntimeException(
                    "Stock batch tidak cukup untuk product {$product->code}: ".
                    "needed {$qty}, short ".number_format($remaining, 4)
                );
            }

            $this->syncAggregateStock($productId, $warehouseId);

            return $consumed;
        });
    }

    /**
     * Sync inventory_stock.qty_on_hand dari sum batch_stock.
     * Dipakai supaya aggregate stock tetap konsisten dengan per-batch detail.
     */
    public function syncAggregateStock(int $productId, int $warehouseId): void
    {
        $totalOnHand = InventoryBatchStock::query()
            ->join('inventory_batches', 'inventory_batches.id', '=', 'inventory_batch_stock.batch_id')
            ->where('inventory_batches.product_id', $productId)
            ->where('inventory_batch_stock.warehouse_id', $warehouseId)
            ->sum('inventory_batch_stock.qty_on_hand');

        InventoryStock::updateOrCreate(
            ['product_id' => $productId, 'warehouse_id' => $warehouseId],
            ['qty_on_hand' => $totalOnHand, 'last_movement_at' => now()],
        );

        // Sync product cache juga
        $product = Product::find($productId);
        if ($product) {
            $product->total_stock_cached = InventoryStock::where('product_id', $productId)
                ->sum('qty_on_hand');
            $product->save();
        }
    }

    /**
     * Trace - recall capability. Cari semua movement output dari batch tertentu
     * (siapa yang nge-consume kemana - WO/Shipment).
     */
    public function trace(InventoryBatch $batch): array
    {
        $movements = InventoryMovement::where('batch_id', $batch->id)
            ->orderBy('created_at')
            ->get();

        return [
            'batch' => [
                'id' => $batch->id,
                'code' => $batch->batch_code,
                'product_id' => $batch->product_id,
                'received_at' => $batch->received_at,
                'expires_at' => $batch->expires_at,
                'supplier_lot' => $batch->supplier_lot,
            ],
            'movements' => $movements->map(fn ($m) => [
                'date' => $m->created_at,
                'reason' => $m->reason,
                'qty' => $m->qty,
                'warehouse_id' => $m->warehouse_id,
                'reference' => $m->reference_type.'#'.$m->reference_id,
            ])->all(),
        ];
    }

    /**
     * List batches yang mendekati expiry - buat dashboard alert.
     * Default: yang expires dalam 30 hari atau yang sudah expired.
     */
    public function expiringBatches(int $companyId, int $daysAhead = 30): array
    {
        return InventoryBatch::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($daysAhead))
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))->from('inventory_batch_stock')
                    ->whereColumn('inventory_batch_stock.batch_id', 'inventory_batches.id')
                    ->where('qty_on_hand', '>', 0);
            })
            ->with('product:id,code,name,unit_of_measure')
            ->orderBy('expires_at')
            ->get()
            ->map(function (InventoryBatch $b) {
                $daysToExpiry = (int) now()->diffInDays($b->expires_at, false);

                return [
                    'batch_id' => $b->id,
                    'batch_code' => $b->batch_code,
                    'product_code' => $b->product?->code,
                    'product_name' => $b->product?->name,
                    'qty_on_hand' => $b->totalOnHand(),
                    'uom' => $b->product?->unit_of_measure,
                    'expires_at' => $b->expires_at->toDateString(),
                    'days_to_expiry' => $daysToExpiry,
                    'severity' => $daysToExpiry < 0 ? 'expired'
                                     : ($daysToExpiry <= 7 ? 'critical'
                                     : ($daysToExpiry <= 30 ? 'warning' : 'ok')),
                ];
            })
            ->all();
    }
}
