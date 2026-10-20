<?php

namespace App\Production\Services;

use App\Models\Product;

/**
 * Monitor stok terhadap reorder_point dan safety_stock per Product.
 *
 * Threshold:
 *   total_stock_cached <= safety_stock  → CRITICAL (urgent - stop production risk)
 *   total_stock_cached <= reorder_point → WARNING  (saatnya order/produksi ulang)
 *   else                                → OK
 *
 * Output dipakai oleh dashboard + MrpService untuk auto-suggest WO/PO.
 */
class InventoryAlertService
{
    /**
     * Scan semua product aktif yang track_stock, filter yang di bawah reorder.
     *
     * @return array of [
     *               'product_id', 'code', 'name', 'uom', 'product_type',
     *               'on_hand', 'reorder_point', 'safety_stock',
     *               'severity' ('critical' | 'warning'),
     *               'gap' (qty to top up sampai reorder_point),
     *               ]
     */
    public function lowStockReport(int $companyId): array
    {
        $rows = Product::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('track_stock', true)
            ->where(function ($q) {
                $q->whereRaw('total_stock_cached <= COALESCE(reorder_point, 0)')
                    ->orWhereRaw('total_stock_cached <= COALESCE(safety_stock, 0)');
            })
            ->get(['id', 'code', 'name', 'unit_of_measure', 'product_type',
                'total_stock_cached', 'reorder_point', 'safety_stock', 'unit_cost'])
            ->map(function (Product $p) {
                $stock = (float) $p->total_stock_cached;
                $reorder = (float) ($p->reorder_point ?? 0);
                $safety = (float) ($p->safety_stock ?? 0);
                $severity = $stock <= $safety ? 'critical' : 'warning';

                return [
                    'product_id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->name,
                    'uom' => $p->unit_of_measure,
                    'product_type' => $p->product_type,
                    'on_hand' => $stock,
                    'reorder_point' => $reorder,
                    'safety_stock' => $safety,
                    'unit_cost' => (float) $p->unit_cost,
                    'severity' => $severity,
                    'gap' => max(0, $reorder - $stock),
                ];
            })
            ->sortByDesc(fn ($r) => $r['severity'] === 'critical' ? 2 : 1)
            ->values()
            ->all();

        return $rows;
    }

    /** Quick summary buat dashboard widget */
    public function summary(int $companyId): array
    {
        $report = $this->lowStockReport($companyId);
        $critical = collect($report)->where('severity', 'critical');
        $warning = collect($report)->where('severity', 'warning');

        return [
            'critical_count' => $critical->count(),
            'warning_count' => $warning->count(),
            'critical_value' => $critical->sum(fn ($r) => $r['gap'] * $r['unit_cost']),
            'warning_value' => $warning->sum(fn ($r) => $r['gap'] * $r['unit_cost']),
        ];
    }
}
