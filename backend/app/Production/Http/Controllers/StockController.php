<?php

namespace App\Production\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Production\Services\StockService;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockController extends ApiController
{
    public function __construct(
        private readonly StockService $stock,
        private readonly AuditLogService $audit,
    ) {}

    /** GET /admin/inventory/stock - list stock semua product × warehouse */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'inventory.view');

        $q = $request->string('q')->toString();
        $warehouseId = $request->integer('warehouse_id');
        $lowOnly = $request->boolean('low_only', false);

        $rows = InventoryStock::query()
            ->where('inventory_stock.company_id', $user->company_id)
            ->join('products as p', 'p.id', '=', 'inventory_stock.product_id')
            ->join('inventory_warehouses as w', 'w.id', '=', 'inventory_stock.warehouse_id')
            ->when($warehouseId, fn ($x) => $x->where('inventory_stock.warehouse_id', $warehouseId))
            ->when($q, fn ($x) => $x->where(function ($y) use ($q) {
                $y->where('p.name', 'like', "%{$q}%")
                    ->orWhere('p.code', 'like', "%{$q}%");
            }))
            ->when($lowOnly, fn ($x) => $x->whereRaw('inventory_stock.qty_on_hand <= COALESCE(p.reorder_point, 0)')
                ->whereNotNull('p.reorder_point'))
            ->select([
                'inventory_stock.*',
                'p.code as product_code', 'p.name as product_name', 'p.product_type',
                'p.unit_of_measure', 'p.reorder_point', 'p.safety_stock', 'p.unit_cost',
                'w.code as warehouse_code', 'w.name as warehouse_name',
            ])
            ->orderBy('p.name')
            ->paginate(50);

        return $this->paginated($rows, $rows->items());
    }

    /**
     * GET /admin/inventory/reorder-alerts
     *
     * Products dengan total stock <= reorder_point. Tampilkan per-warehouse breakdown
     * + suggested reorder qty (reorder_point * 2 - current, minimum safety_stock).
     */
    public function reorderAlerts(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'inventory.view');

        $products = Product::query()
            ->where('company_id', $user->company_id)
            ->where('track_stock', true)
            ->whereNotNull('reorder_point')
            ->where('reorder_point', '>', 0)
            ->get(['id', 'code', 'name', 'product_type', 'unit_of_measure', 'reorder_point', 'safety_stock', 'unit_cost', 'total_stock_cached']);

        $alerts = [];
        $totalEstimatedCost = 0.0;
        foreach ($products as $p) {
            // sum across all warehouses
            $totalOnHand = (float) (\DB::table('inventory_stock')
                ->where('company_id', $user->company_id)
                ->where('product_id', $p->id)
                ->sum('qty_on_hand'));

            if ($totalOnHand > $p->reorder_point) {
                continue;
            }

            $suggested = max(
                ($p->reorder_point * 2) - $totalOnHand,
                (float) ($p->safety_stock ?? 0),
                1.0,
            );
            $estCost = $suggested * (float) ($p->unit_cost ?? 0);
            $totalEstimatedCost += $estCost;

            // per-warehouse breakdown
            $perWarehouse = \DB::table('inventory_stock as s')
                ->join('inventory_warehouses as w', 'w.id', '=', 's.warehouse_id')
                ->where('s.company_id', $user->company_id)
                ->where('s.product_id', $p->id)
                ->select('w.code', 'w.name', 's.qty_on_hand', 's.qty_reserved')
                ->get()
                ->map(fn ($r) => [
                    'warehouse_code' => $r->code,
                    'warehouse_name' => $r->name,
                    'qty_on_hand' => (float) $r->qty_on_hand,
                    'qty_reserved' => (float) $r->qty_reserved,
                ])->all();

            $severity = $totalOnHand <= 0 ? 'critical'
                : ($totalOnHand <= ($p->safety_stock ?? 0) ? 'high'
                : 'medium');

            $alerts[] = [
                'product_id' => $p->id,
                'product_code' => $p->code,
                'product_name' => $p->name,
                'product_type' => $p->product_type,
                'unit_of_measure' => $p->unit_of_measure,
                'reorder_point' => (float) $p->reorder_point,
                'safety_stock' => (float) ($p->safety_stock ?? 0),
                'total_on_hand' => $totalOnHand,
                'shortage' => round(max(0, $p->reorder_point - $totalOnHand), 2),
                'suggested_qty' => round($suggested, 2),
                'unit_cost' => (float) ($p->unit_cost ?? 0),
                'estimated_cost' => round($estCost, 2),
                'severity' => $severity,
                'warehouses' => $perWarehouse,
            ];
        }

        // Sort: critical first, then by shortage
        usort($alerts, function ($a, $b) {
            $order = ['critical' => 0, 'high' => 1, 'medium' => 2];
            $sa = $order[$a['severity']] ?? 9;
            $sb = $order[$b['severity']] ?? 9;

            return $sa !== $sb ? $sa <=> $sb : $b['shortage'] <=> $a['shortage'];
        });

        return $this->success([
            'data' => $alerts,
            'total_alerts' => count($alerts),
            'critical_count' => count(array_filter($alerts, fn ($a) => $a['severity'] === 'critical')),
            'high_count' => count(array_filter($alerts, fn ($a) => $a['severity'] === 'high')),
            'medium_count' => count(array_filter($alerts, fn ($a) => $a['severity'] === 'medium')),
            'total_estimated_reorder_cost' => round($totalEstimatedCost, 2),
        ]);
    }

    /** GET /admin/inventory/products - list products (untuk picker) */
    public function products(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'inventory.view');

        $q = $request->string('q')->toString();
        $type = $request->string('product_type')->toString();

        $rows = Product::query()
            ->where('company_id', $user->company_id)
            ->where('track_stock', true)
            ->when($type, fn ($x) => $x->where('product_type', $type))
            ->when($q, fn ($x) => $x->where(function ($y) use ($q) {
                $y->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%");
            }))
            ->orderBy('product_type')
            ->orderBy('name')
            ->get([
                'id', 'code', 'name', 'product_type', 'unit_of_measure',
                'unit_cost', 'reorder_point', 'safety_stock', 'total_stock_cached',
            ]);

        return $this->success($rows);
    }

    /** POST /admin/inventory/move - manual stock movement */
    public function move(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'inventory.manage');

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'warehouse_id' => ['required', 'integer', 'exists:inventory_warehouses,id'],
            'qty_delta' => ['required', 'numeric', 'not_in:0'],
            'reason' => ['required', 'in:initial_load,return_in,return_out,damage,adjustment'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $movement = $this->stock->move(
                $user,
                $data['product_id'],
                $data['warehouse_id'],
                (float) $data['qty_delta'],
                $data['reason'],
                ['unit_cost' => $data['unit_cost'] ?? null, 'notes' => $data['notes'] ?? null],
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->audit->log('inventory.movement', $user, 'stock', (string) $movement->id, [
            'product_id' => $data['product_id'],
            'warehouse' => $data['warehouse_id'],
            'delta' => $data['qty_delta'],
            'reason' => $data['reason'],
        ]);

        return $this->created($movement, 'Movement tercatat.');
    }

    /** POST /admin/inventory/transfer */
    public function transfer(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'inventory.manage');

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'from_warehouse_id' => ['required', 'integer', 'exists:inventory_warehouses,id'],
            'to_warehouse_id' => ['required', 'integer', 'exists:inventory_warehouses,id', 'different:from_warehouse_id'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $result = $this->stock->transfer(
                $user,
                $data['product_id'],
                $data['from_warehouse_id'],
                $data['to_warehouse_id'],
                (float) $data['qty'],
                $data['notes'] ?? null,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->success($result, 'Transfer berhasil.');
    }

    /** POST /admin/inventory/adjust - stock take */
    public function adjust(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'inventory.manage');

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'warehouse_id' => ['required', 'integer', 'exists:inventory_warehouses,id'],
            'new_qty_on_hand' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $movement = $this->stock->adjust(
                $user,
                $data['product_id'],
                $data['warehouse_id'],
                (float) $data['new_qty_on_hand'],
                $data['reason'],
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->success($movement, 'Stock adjustment recorded.');
    }

    /** GET /admin/inventory/movements - audit trail */
    public function movements(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'inventory.view');

        $productId = $request->integer('product_id');
        $warehouseId = $request->integer('warehouse_id');

        $rows = InventoryMovement::query()
            ->where('inventory_movements.company_id', $user->company_id)
            ->join('products as p', 'p.id', '=', 'inventory_movements.product_id')
            ->join('inventory_warehouses as w', 'w.id', '=', 'inventory_movements.warehouse_id')
            ->when($productId, fn ($x) => $x->where('inventory_movements.product_id', $productId))
            ->when($warehouseId, fn ($x) => $x->where('inventory_movements.warehouse_id', $warehouseId))
            ->select([
                'inventory_movements.*',
                'p.code as product_code', 'p.name as product_name',
                'w.code as warehouse_code', 'w.name as warehouse_name',
            ])
            ->orderByDesc('inventory_movements.created_at')
            ->paginate(50);

        return $this->paginated($rows, $rows->items());
    }
}
