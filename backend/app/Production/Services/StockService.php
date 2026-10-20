<?php

namespace App\Production\Services;

use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Stock operations untuk modul Produksi - semua mutation stock HARUS via service ini.
 *
 * Beda dari App\Warehouse\Services\InventoryService (yang track laptop/peralatan kantor) -
 * ini track komponen produksi & finished goods per warehouse.
 *
 * Invariant:
 *   - Setiap perubahan stock = append movement record (immutable audit)
 *   - Tidak ada direct UPDATE qty_on_hand di luar service ini
 *   - Atomic via DB transaction + lockForUpdate (prevent race)
 */
class StockService
{
    /**
     * Move stock - primary entry point.
     *
     * @throws \RuntimeException kalau OUT melebihi stock
     */
    public function move(
        User $actor,
        int $productId,
        int $warehouseId,
        float $qtyDelta,
        string $reason,
        array $opts = [],
    ): InventoryMovement {
        if ($qtyDelta == 0) {
            throw new \InvalidArgumentException('Qty delta cannot be zero');
        }

        return DB::transaction(function () use ($actor, $productId, $warehouseId, $qtyDelta, $reason, $opts) {
            $product = Product::query()->where('id', $productId)
                ->where('company_id', $actor->company_id)
                ->lockForUpdate()->firstOrFail();

            if (! $product->track_stock) {
                throw new \RuntimeException("Product {$product->code} tipe {$product->product_type} tidak di-track stock");
            }

            $warehouse = InventoryWarehouse::query()
                ->where('id', $warehouseId)
                ->where('company_id', $actor->company_id)
                ->where('is_active', true)
                ->firstOrFail();

            $stock = InventoryStock::query()
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                if ($qtyDelta < 0) {
                    throw new \RuntimeException(
                        "Stock tidak ada: {$product->code} di {$warehouse->name} (qty=0)"
                    );
                }
                $stock = InventoryStock::create([
                    'company_id' => $actor->company_id,
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'qty_on_hand' => 0,
                    'qty_reserved' => 0,
                ]);
            }

            $qtyBefore = (float) $stock->qty_on_hand;
            $qtyAfter = $qtyBefore + $qtyDelta;

            if ($qtyAfter < 0) {
                throw new \RuntimeException(
                    "Stock {$product->code} di {$warehouse->name} insufficient: {$qtyBefore}, butuh {$qtyDelta}"
                );
            }

            $stock->update([
                'qty_on_hand' => $qtyAfter,
                'last_movement_at' => now(),
            ]);

            $movement = InventoryMovement::create([
                'company_id' => $actor->company_id,
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'qty_delta' => $qtyDelta,
                'qty_before' => $qtyBefore,
                'qty_after' => $qtyAfter,
                'unit_cost' => $opts['unit_cost'] ?? null,
                'reason' => $reason,
                'reference_type' => $opts['reference_type'] ?? null,
                'reference_id' => $opts['reference_id'] ?? null,
                'notes' => $opts['notes'] ?? null,
                'performed_by' => $actor->id,
            ]);

            // Update cached total stock di product
            $totalCached = InventoryStock::query()
                ->where('product_id', $productId)
                ->sum('qty_on_hand');
            $product->update(['total_stock_cached' => $totalCached]);

            // Weighted average cost saat purchase receipt
            if ($reason === 'purchase_receipt' && isset($opts['unit_cost']) && $qtyDelta > 0) {
                $this->recomputeWeightedAvgCost($product, $qtyDelta, (float) $opts['unit_cost'], $qtyBefore);
            }

            return $movement;
        });
    }

    /** Transfer stock antar warehouse - atomic (2 movements, rollback if either fails) */
    public function transfer(
        User $actor,
        int $productId,
        int $fromWarehouseId,
        int $toWarehouseId,
        float $qty,
        ?string $notes = null,
    ): array {
        if ($qty <= 0) {
            throw new \InvalidArgumentException('Transfer qty harus positif');
        }
        if ($fromWarehouseId === $toWarehouseId) {
            throw new \InvalidArgumentException('Source = destination');
        }

        return DB::transaction(function () use ($actor, $productId, $fromWarehouseId, $toWarehouseId, $qty, $notes) {
            $out = $this->move($actor, $productId, $fromWarehouseId, -$qty, 'transfer_out', [
                'reference_type' => 'warehouse_transfer',
                'reference_id' => "to:{$toWarehouseId}",
                'notes' => $notes,
            ]);
            $in = $this->move($actor, $productId, $toWarehouseId, $qty, 'transfer_in', [
                'reference_type' => 'warehouse_transfer',
                'reference_id' => "from:{$fromWarehouseId}",
                'notes' => $notes,
            ]);

            return ['out' => $out, 'in' => $in];
        });
    }

    /** Stock take adjustment - koreksi physical count ke system. */
    public function adjust(
        User $actor,
        int $productId,
        int $warehouseId,
        float $newQtyOnHand,
        string $reasonText,
    ): InventoryMovement {
        return DB::transaction(function () use ($actor, $productId, $warehouseId, $newQtyOnHand, $reasonText) {
            $stock = InventoryStock::query()
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()->first();

            $currentQty = $stock ? (float) $stock->qty_on_hand : 0;
            $delta = $newQtyOnHand - $currentQty;

            if ($delta == 0) {
                throw new \RuntimeException('Qty system sudah sama dengan physical count');
            }

            return $this->move($actor, $productId, $warehouseId, $delta, 'adjustment', [
                'notes' => "Stock take: system={$currentQty} → physical={$newQtyOnHand}. {$reasonText}",
            ]);
        });
    }

    /** Weighted average cost recalculation. */
    private function recomputeWeightedAvgCost(Product $product, float $inQty, float $inCost, float $beforeQty): void
    {
        $oldCost = (float) ($product->unit_cost ?? 0);
        $totalBefore = $beforeQty * $oldCost;
        $totalIn = $inQty * $inCost;
        $newQty = $beforeQty + $inQty;
        $newAvg = $newQty > 0 ? ($totalBefore + $totalIn) / $newQty : $inCost;
        $product->update(['unit_cost' => round($newAvg, 2)]);
    }
}
