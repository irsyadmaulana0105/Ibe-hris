<?php

namespace App\Production\Services;

use App\Models\Bom;
use App\Models\Product;

/**
 * MRP-Lite - Material Requirements Planning sederhana.
 *
 * Algoritma:
 *   1. Cari finished goods di bawah reorder_point → suggest WO (qty = reorder + safety)
 *   2. Explode BOM dari WO suggestion → kumpulkan total material requirement
 *   3. Bandingkan kebutuhan vs stock + reserve → gap = material shortage
 *   4. Suggest PO untuk material shortage (qty = max(gap, reorder_point - stock))
 *
 * Output bukan action - cuma saran. User PIC produksi/purchasing tetap perlu
 * approve untuk jadi WO/PO actual.
 *
 * Catatan implementasi:
 *   - Pakai BomService::explode untuk multi-level
 *   - Aggregate per material across multiple finished goods yang butuh material sama
 *   - Suggestion gak nyimpan ke DB - pure read-only computation
 */
class MrpService
{
    public function __construct(
        private readonly BomService $bomSvc,
        private readonly InventoryAlertService $alertSvc,
    ) {}

    /**
     * Generate full MRP suggestion package.
     *
     * Return: [
     *   'production_suggestions' => list WO yg disarankan dibuat,
     *   'procurement_suggestions' => list PO yg disarankan dibuat,
     *   'summary' => statistik (total cost, item counts),
     * ]
     */
    public function suggest(int $companyId): array
    {
        // ─── Step 1: Identifikasi finished goods yang perlu di-produce ──
        $alerts = $this->alertSvc->lowStockReport($companyId);
        $finishedGoodAlerts = collect($alerts)->where('product_type', 'finished_good');

        $productionSuggestions = [];
        $aggregateMaterialDemand = []; // pid => qty needed total across all WO suggestions

        foreach ($finishedGoodAlerts as $alert) {
            $bom = Bom::query()
                ->where('product_id', $alert['product_id'])
                ->where('is_active', true)
                ->first();

            if (! $bom) {
                // Finished good tanpa BOM aktif - gak bisa di-suggest, perlu user setup BOM dulu
                $productionSuggestions[] = [
                    'product_id' => $alert['product_id'],
                    'code' => $alert['code'],
                    'name' => $alert['name'],
                    'qty_suggest' => 0,
                    'status' => 'no_bom',
                    'reason' => 'BOM aktif belum ada untuk produk ini',
                    'severity' => $alert['severity'],
                ];

                continue;
            }

            // Qty yg disarankan: gap sampai reorder_point + 1 safety_stock buffer
            $qtySuggest = $alert['gap'] + max(0, $alert['safety_stock']);
            if ($qtySuggest <= 0) {
                continue;
            }

            try {
                $explosion = $this->bomSvc->explode($bom, $qtySuggest);
            } catch (\Throwable $e) {
                $productionSuggestions[] = [
                    'product_id' => $alert['product_id'],
                    'code' => $alert['code'],
                    'name' => $alert['name'],
                    'qty_suggest' => $qtySuggest,
                    'status' => 'error',
                    'reason' => 'BOM explosion gagal: '.$e->getMessage(),
                    'severity' => $alert['severity'],
                ];

                continue;
            }

            // Akumulasi kebutuhan material ke aggregateMaterialDemand
            foreach ($explosion['leaves'] as $pid => $leaf) {
                if (! isset($aggregateMaterialDemand[$pid])) {
                    $aggregateMaterialDemand[$pid] = [
                        'product_id' => $pid,
                        'code' => $leaf['code'],
                        'name' => $leaf['name'],
                        'uom' => $leaf['uom'],
                        'unit_cost' => $leaf['unit_cost'],
                        'qty_required' => 0,
                        'wo_sources' => [],
                    ];
                }
                $aggregateMaterialDemand[$pid]['qty_required'] += $leaf['qty_required'];
                $aggregateMaterialDemand[$pid]['wo_sources'][] = $alert['code'];
            }

            $productionSuggestions[] = [
                'product_id' => $alert['product_id'],
                'code' => $alert['code'],
                'name' => $alert['name'],
                'on_hand' => $alert['on_hand'],
                'reorder_point' => $alert['reorder_point'],
                'qty_suggest' => $qtySuggest,
                'estimated_unit_cost' => $bom->total_cost_cached,
                'estimated_total_cost' => (float) $bom->total_cost_cached * $qtySuggest,
                'status' => 'ready',
                'bom_id' => $bom->id,
                'subassemblies_count' => count($explosion['subassemblies']),
                'severity' => $alert['severity'],
            ];
        }

        // ─── Step 2: Untuk material shortage → suggest PO ──────────────
        $procurementSuggestions = [];
        foreach ($aggregateMaterialDemand as $pid => $demand) {
            $product = Product::find($pid);
            if (! $product) {
                continue;
            }
            $onHand = (float) $product->total_stock_cached;
            $shortage = $demand['qty_required'] - $onHand;
            if ($shortage <= 0) {
                continue;
            } // stock cukup

            // PO qty: shortage + safety buffer
            $poQty = $shortage + max(0, (float) ($product->safety_stock ?? 0));
            $procurementSuggestions[] = [
                'product_id' => $pid,
                'code' => $demand['code'],
                'name' => $demand['name'],
                'uom' => $demand['uom'],
                'on_hand' => $onHand,
                'demand' => $demand['qty_required'],
                'shortage' => $shortage,
                'qty_suggest_po' => $poQty,
                'unit_cost' => $demand['unit_cost'],
                'estimated_cost' => $poQty * $demand['unit_cost'],
                'demand_sources' => array_unique($demand['wo_sources']),
                'product_type' => $product->product_type,
            ];
        }

        // Sort by impact
        usort($procurementSuggestions, fn ($a, $b) => $b['shortage'] <=> $a['shortage']);
        usort($productionSuggestions, function ($a, $b) {
            $sevOrder = ['critical' => 0, 'warning' => 1, 'no_bom' => 2, 'error' => 3];

            return ($sevOrder[$a['severity']] ?? 9) <=> ($sevOrder[$b['severity']] ?? 9);
        });

        return [
            'production_suggestions' => $productionSuggestions,
            'procurement_suggestions' => $procurementSuggestions,
            'summary' => [
                'wo_suggested' => count($productionSuggestions),
                'po_suggested' => count($procurementSuggestions),
                'estimated_wo_cost' => collect($productionSuggestions)->sum('estimated_total_cost'),
                'estimated_po_cost' => collect($procurementSuggestions)->sum('estimated_cost'),
                'critical_finished' => collect($productionSuggestions)->where('severity', 'critical')->count(),
            ],
        ];
    }
}
