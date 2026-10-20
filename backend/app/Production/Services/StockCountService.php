<?php

namespace App\Production\Services;

use App\Models\InventoryStock;
use App\Models\InventoryWarehouse;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cycle counting / stock take service.
 *
 * Lifecycle:
 *   1. create(warehouse, filter) - bikin session draft
 *   2. start(session, lines) - generate lines dengan system_qty snapshot,
 *                              transisi ke 'counting'
 *   3. recordCount(line, counted_qty) - operator input qty per line
 *   4. completeCount(session) - semua lines counted, transisi 'reconciliation'
 *   5. post(session, approver) - apply adjustment ke InventoryMovement,
 *                                update stock, transisi 'posted'
 *   6. cancel(session) - buang session tanpa post
 *
 * Blind count guarantee: line.system_qty di-hide di response sampai status
 * line == 'counted' (di Resource layer). Service layer expose untuk
 * controller, controller yang filter visibility.
 */
class StockCountService
{
    public function __construct(
        private readonly StockService $stock,
    ) {}

    /** Generate count number: CC-2026-0001 (per company-year) */
    public function generateCountNumber(int $companyId): string
    {
        $year = now()->year;
        $prefix = "CC-{$year}-";

        return DB::transaction(function () use ($companyId, $prefix) {
            $last = StockCount::query()->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('count_number', 'like', $prefix.'%')
                ->orderByDesc('count_number')
                ->lockForUpdate()
                ->first(['count_number']);
            $nextSeq = $last ? ((int) substr($last->count_number, strlen($prefix))) + 1 : 1;

            return $prefix.str_pad((string) $nextSeq, 4, '0', STR_PAD_LEFT);
        });
    }

    /** Create new draft count session. */
    public function create(User $actor, array $data): StockCount
    {
        return DB::transaction(function () use ($actor, $data) {
            return StockCount::create([
                'company_id' => $actor->company_id,
                'count_number' => $this->generateCountNumber($actor->company_id),
                'warehouse_id' => $data['warehouse_id'],
                'count_date' => $data['count_date'] ?? now()->toDateString(),
                'status' => 'draft',
                'created_by' => $actor->id,
                'notes' => $data['notes'] ?? null,
                'filter_used' => $data['filter'] ?? null,
            ]);
        });
    }

    /**
     * Transisi draft → counting + bikin lines.
     * $productIds: kalau null, auto-include semua product di warehouse yang track_stock
     * (sesuai filter di filter_used).
     */
    public function start(StockCount $count, User $actor, ?array $productIds = null): StockCount
    {
        if ($count->status !== 'draft') {
            throw new \RuntimeException("Cannot start: status {$count->status}");
        }

        return DB::transaction(function () use ($count, $actor, $productIds) {
            $query = InventoryStock::query()
                ->where('company_id', $count->company_id)
                ->where('warehouse_id', $count->warehouse_id);

            if ($productIds !== null) {
                $query->whereIn('product_id', $productIds);
            } else {
                // Default: semua product yang track_stock di warehouse ini
                $query->whereExists(function ($q) {
                    $q->select(DB::raw(1))->from('products')
                        ->whereColumn('products.id', 'inventory_stock.product_id')
                        ->where('products.track_stock', true);
                });
            }

            $stocks = $query->get(['product_id', 'qty_on_hand']);
            if ($stocks->isEmpty()) {
                throw new \RuntimeException('Tidak ada produk yang bisa di-count di warehouse ini');
            }

            $now = now();
            $lines = [];
            foreach ($stocks as $s) {
                $lines[] = [
                    'company_id' => $count->company_id,
                    'stock_count_id' => $count->id,
                    'product_id' => $s->product_id,
                    'system_qty' => (float) $s->qty_on_hand,
                    'counted_qty' => null,
                    'status' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            StockCountLine::insert($lines);

            $count->update([
                'status' => 'counting',
                'counting_started_at' => $now,
                'counted_by' => $actor->id,
            ]);

            return $count->fresh('lines');
        });
    }

    /**
     * Record count untuk satu line. Status line jadi 'counted'.
     * Kalau semua lines udah counted, auto-transisi session ke 'reconciliation'.
     */
    public function recordCount(StockCountLine $line, User $actor, float $countedQty, ?string $notes = null): StockCountLine
    {
        $count = $line->stockCount;
        if ($count->status !== 'counting') {
            throw new \RuntimeException("Cannot record: session status {$count->status}");
        }
        if ($countedQty < 0) {
            throw new \RuntimeException('Counted qty tidak boleh negatif');
        }

        return DB::transaction(function () use ($line, $actor, $countedQty, $notes, $count) {
            $line->update([
                'counted_qty' => $countedQty,
                'counter_notes' => $notes,
                'counted_by' => $actor->id,
                'counted_at' => now(),
                'status' => 'counted',
            ]);

            // Auto-transisi kalau semua lines counted
            $pending = StockCountLine::where('stock_count_id', $count->id)
                ->where('status', 'pending')
                ->exists();
            if (! $pending) {
                $count->update([
                    'status' => 'reconciliation',
                    'counting_completed_at' => now(),
                ]);
            }

            return $line->fresh();
        });
    }

    /**
     * Post adjustment - apply variance ke InventoryMovement, update qty_on_hand.
     * Movement reason: 'stock_count_adjustment'.
     * Variance positif → IN movement. Variance negatif → OUT movement.
     */
    public function post(StockCount $count, User $approver, ?string $notes = null): StockCount
    {
        return DB::transaction(function () use ($count, $approver, $notes) {
            // The model received from route binding may be stale. Lock and
            // re-read the authoritative session inside the transaction so two
            // concurrent post requests cannot both apply an adjustment.
            $lockedCount = StockCount::query()->withoutGlobalScopes()
                ->where('company_id', $approver->company_id)
                ->whereKey($count->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCount->status !== 'reconciliation') {
                throw new \RuntimeException("Cannot post: status {$lockedCount->status}");
            }

            $lines = StockCountLine::query()->withoutGlobalScopes()
                ->where('company_id', $lockedCount->company_id)
                ->where('stock_count_id', $lockedCount->id)
                ->where('status', 'counted')
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get();

            if ($lines->isEmpty()) {
                throw new \RuntimeException('Cannot post: tidak ada line counted');
            }

            // Match StockService's lock order (product -> warehouse -> stock)
            // before reading current stock. Locking stock first would deadlock
            // against a concurrent normal stock movement that already holds
            // the product row.
            $productIds = $lines->pluck('product_id')->unique()->sort()->values();
            $lockedProducts = Product::query()->withoutGlobalScopes()
                ->where('company_id', $lockedCount->company_id)
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
            if ($lockedProducts->count() !== $productIds->count()) {
                throw new \RuntimeException('Cannot post: product stock count tidak valid untuk tenant aktif');
            }
            InventoryWarehouse::query()->withoutGlobalScopes()
                ->where('company_id', $lockedCount->company_id)
                ->whereKey($lockedCount->warehouse_id)
                ->lockForUpdate()
                ->firstOrFail();

            foreach ($lines as $line) {
                // system_qty is only the blind-count snapshot. Stock may have
                // legitimately moved while counting was in progress, so the
                // posting delta must be calculated from the *current locked*
                // stock row. This makes counted_qty the final physical truth.
                $currentStock = InventoryStock::query()->withoutGlobalScopes()
                    ->where('company_id', $lockedCount->company_id)
                    ->where('product_id', $line->product_id)
                    ->where('warehouse_id', $lockedCount->warehouse_id)
                    ->lockForUpdate()
                    ->first();
                $currentQty = (float) ($currentStock?->qty_on_hand ?? 0);
                $physicalQty = (float) $line->counted_qty;
                $delta = $physicalQty - $currentQty;

                if (abs($delta) > 0.0001) { // no movement when already equal
                    $this->stock->move(
                        $approver,
                        $line->product_id,
                        $lockedCount->warehouse_id,
                        $delta, // positive = IN, negative = OUT
                        'adjustment',
                        [
                            'reference_type' => 'StockCount',
                            'reference_id' => (string) $lockedCount->id,
                            'notes' => "Cycle count {$lockedCount->count_number}: snapshot={$line->system_qty} current={$currentQty} counted={$physicalQty} delta={$delta}",
                        ],
                    );
                }
                $line->update([
                    'status' => 'posted',
                    'approver_notes' => $notes,
                ]);
            }

            $lockedCount->update([
                'status' => 'posted',
                'approved_by' => $approver->id,
                'posted_at' => now(),
                'notes' => trim(($lockedCount->notes ?? '')."\n".($notes ?? '')),
            ]);

            return $lockedCount->fresh('lines');
        });
    }

    public function cancel(StockCount $count, User $actor, string $reason): StockCount
    {
        if (in_array($count->status, ['posted', 'canceled'], true)) {
            throw new \RuntimeException("Cannot cancel: status {$count->status}");
        }
        $count->update([
            'status' => 'canceled',
            'notes' => trim(($count->notes ?? '')."\nCanceled by {$actor->name}: {$reason}"),
        ]);

        return $count->fresh();
    }

    /**
     * Variance summary untuk session aktif/posted - buat dashboard.
     */
    public function variance(StockCount $count): array
    {
        $lines = StockCountLine::where('stock_count_id', $count->id)->get();
        $variances = $lines->map(fn ($l) => (float) ($l->variance_qty ?? 0))->filter();

        return [
            'lines_total' => $lines->count(),
            'lines_counted' => $lines->where('status', '!=', 'pending')->count(),
            'lines_variance' => $lines->filter(fn ($l) => abs((float) ($l->variance_qty ?? 0)) > 0.0001)->count(),
            'total_pos' => $variances->filter(fn ($v) => $v > 0)->sum(),
            'total_neg' => $variances->filter(fn ($v) => $v < 0)->sum(),
            'absolute_total' => $variances->sum(fn ($v) => abs($v)),
        ];
    }
}
