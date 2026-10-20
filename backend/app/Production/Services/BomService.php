<?php

namespace App\Production\Services;

use App\Models\Bom;
use App\Models\BomComponent;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * BOM operations: build, activate, cost roll-up, feasibility check.
 *
 * Cost roll-up formula:
 *   Total Material Cost = sum(component.qty_required × component.unit_cost)
 *   Total Cost          = Material + Labor + Overhead
 *
 * Feasibility check (canProduce):
 *   Untuk produksi N unit finished_good, butuh qty × N untuk setiap component.
 *   Check stock total (across all warehouses) >= required.
 */
class BomService
{
    /** Bulk replace components untuk a BOM. Auto-snapshot unit_cost. */
    public function replaceComponents(User $actor, Bom $bom, array $components): Bom
    {
        return DB::transaction(function () use ($actor, $bom, $components) {
            BomComponent::where('bom_id', $bom->id)->delete();

            foreach ($components as $i => $c) {
                $product = Product::query()
                    ->where('id', $c['component_product_id'])
                    ->where('company_id', $actor->company_id)
                    ->firstOrFail();

                BomComponent::create([
                    'company_id' => $actor->company_id,
                    'bom_id' => $bom->id,
                    'component_product_id' => $product->id,
                    'qty_required' => $c['qty_required'],
                    'unit_of_measure' => $c['unit_of_measure'] ?? $product->unit_of_measure,
                    'unit_cost_snapshot' => $product->unit_cost,
                    'notes' => $c['notes'] ?? null,
                    'sort_order' => $i * 10,
                ]);
            }

            $this->refreshTotalCost($bom);

            return $bom->fresh('components');
        });
    }

    /** Recompute total cost cached berdasarkan current component costs. */
    public function refreshTotalCost(Bom $bom): float
    {
        $material = BomComponent::query()
            ->where('bom_id', $bom->id)
            ->join('products', 'products.id', '=', 'bom_components.component_product_id')
            ->selectRaw('COALESCE(SUM(bom_components.qty_required * products.unit_cost), 0) as total')
            ->value('total');

        $total = (float) $material + (float) $bom->labor_cost + (float) $bom->overhead_cost;
        $bom->update(['total_cost_cached' => $total]);

        return $total;
    }

    /**
     * Activate this BOM, deactivate semua BOM lain untuk product yang sama.
     * Atomic - kalau ada concurrent activate race, last-write wins.
     */
    public function activate(Bom $bom): Bom
    {
        return DB::transaction(function () use ($bom) {
            Bom::query()
                ->where('company_id', $bom->company_id)
                ->where('product_id', $bom->product_id)
                ->where('id', '!=', $bom->id)
                ->update(['is_active' => false]);
            $bom->update(['is_active' => true]);

            return $bom->fresh();
        });
    }

    /**
     * Multi-level BOM explosion - flatten nested BOMs jadi flat list of
     * leaf raw materials yang dibutuhkan untuk produksi $units unit.
     *
     * Algoritma: recursive descend. Setiap component dicek apakah punya
     * active BOM sendiri - kalau iya, rekursi (sub-assembly). Kalau gak,
     * itu leaf raw material → akumulasi qty di output.
     *
     * Safety:
     *   - Cycle detection via $path (cegah infinite loop kalau BOM circular)
     *   - Max depth 10 (safety net)
     *
     * Return shape:
     *   [
     *     'leaves' => [product_id => ['code', 'name', 'uom', 'qty_required',
     *                                  'unit_cost', 'total_cost']],
     *     'tree'   => recursive tree untuk visualization,
     *     'subassemblies' => list of sub-assembly BOMs yang ditemui
     *       (info aja, bukan untuk consumption - consumption tetap di leaves),
     *     'total_material_cost' => sum semua leaves
     *   ]
     */
    public function explode(Bom $bom, float $units = 1.0, array $path = [], int $depth = 0): array
    {
        if ($depth > 10) {
            throw new \RuntimeException('BOM explosion depth > 10 - kemungkinan cycle. Path: '.implode(' → ', $path));
        }
        if (in_array($bom->id, $path, true)) {
            throw new \RuntimeException("BOM cycle detected pada BOM #{$bom->id}. Path: ".implode(' → ', $path));
        }

        $leaves = [];
        $tree = [];
        $subs = [];
        $newPath = [...$path, $bom->id];

        $components = BomComponent::query()
            ->where('bom_id', $bom->id)
            ->join('products', 'products.id', '=', 'bom_components.component_product_id')
            ->select([
                'bom_components.*',
                'products.code as comp_code',
                'products.name as comp_name',
                'products.unit_of_measure as comp_uom',
                'products.unit_cost as comp_unit_cost',
            ])
            ->get();

        foreach ($components as $c) {
            $qtyTotal = (float) $c->qty_required * $units;

            // Cari active BOM untuk component ini (sub-assembly?)
            $childBom = Bom::query()
                ->where('product_id', $c->component_product_id)
                ->where('is_active', true)
                ->first();

            if ($childBom) {
                // Sub-assembly → recurse
                $subs[] = [
                    'product_id' => $c->component_product_id,
                    'code' => $c->comp_code,
                    'name' => $c->comp_name,
                    'qty_units' => $qtyTotal,
                    'bom_id' => $childBom->id,
                ];
                $childResult = $this->explode($childBom, $qtyTotal, $newPath, $depth + 1);
                // Merge leaves into accumulator (sum qty kalau material yang sama muncul di beberapa cabang)
                foreach ($childResult['leaves'] as $pid => $leaf) {
                    if (isset($leaves[$pid])) {
                        $leaves[$pid]['qty_required'] += $leaf['qty_required'];
                        $leaves[$pid]['total_cost'] += $leaf['total_cost'];
                    } else {
                        $leaves[$pid] = $leaf;
                    }
                }
                $tree[] = [
                    'product_id' => $c->component_product_id,
                    'code' => $c->comp_code,
                    'name' => $c->comp_name,
                    'qty_total' => $qtyTotal,
                    'is_assembly' => true,
                    'children' => $childResult['tree'],
                ];
            } else {
                // Leaf raw material
                $unitCost = (float) $c->comp_unit_cost;
                if (isset($leaves[$c->component_product_id])) {
                    $leaves[$c->component_product_id]['qty_required'] += $qtyTotal;
                    $leaves[$c->component_product_id]['total_cost'] += $qtyTotal * $unitCost;
                } else {
                    $leaves[$c->component_product_id] = [
                        'product_id' => $c->component_product_id,
                        'code' => $c->comp_code,
                        'name' => $c->comp_name,
                        'uom' => $c->comp_uom,
                        'qty_required' => $qtyTotal,
                        'unit_cost' => $unitCost,
                        'total_cost' => $qtyTotal * $unitCost,
                    ];
                }
                $tree[] = [
                    'product_id' => $c->component_product_id,
                    'code' => $c->comp_code,
                    'name' => $c->comp_name,
                    'qty_total' => $qtyTotal,
                    'is_assembly' => false,
                ];
            }
        }

        $totalMaterialCost = array_sum(array_column($leaves, 'total_cost'));

        return [
            'leaves' => $leaves,
            'tree' => $tree,
            'subassemblies' => $subs,
            'total_material_cost' => $totalMaterialCost,
            'units_planned' => $units,
        ];
    }

    /**
     * Feasibility check multi-level - gunakan exploded leaves (raw materials),
     * BUKAN top-level components saja. Lebih akurat untuk produk dengan
     * sub-assembly: kalau sub-assembly OOS tapi raw material cukup, masih
     * feasible (akan trigger sub WO).
     *
     * Untuk simplicity backward-compat: kalau BOM gak punya sub-assembly,
     * behavior sama dengan canProduce() lama.
     */
    public function canProduceDeep(Bom $bom, float $units): array
    {
        $explosion = $this->explode($bom, $units);
        $missing = [];
        foreach ($explosion['leaves'] as $pid => $leaf) {
            $available = (float) (Product::find($pid)?->total_stock_cached ?? 0);
            if ($available < $leaf['qty_required']) {
                $missing[] = [
                    'component_id' => $pid,
                    'code' => $leaf['code'],
                    'name' => $leaf['name'],
                    'needed' => $leaf['qty_required'],
                    'available' => $available,
                    'short' => $leaf['qty_required'] - $available,
                    'uom' => $leaf['uom'],
                ];
            }
        }

        return [
            'feasible' => empty($missing),
            'missing' => $missing,
            'units' => $units,
            'total_material_cost' => $explosion['total_material_cost'],
            'subassemblies' => $explosion['subassemblies'],
        ];
    }

    /**
     * Cek feasibility - apakah stock cukup untuk produksi $qty unit dari product BOM ini.
     * Return: [
     *   'feasible' => bool,
     *   'missing'  => array of [component_id, name, needed, available, short],
     * ]
     */
    public function canProduce(Bom $bom, int $unitsToProduce): array
    {
        $components = BomComponent::query()
            ->where('bom_id', $bom->id)
            ->join('products', 'products.id', '=', 'bom_components.component_product_id')
            ->select([
                'bom_components.*',
                'products.code as comp_code',
                'products.name as comp_name',
                'products.unit_of_measure as comp_uom',
                'products.total_stock_cached as comp_stock',
            ])
            ->get();

        $missing = [];
        foreach ($components as $c) {
            $needed = (float) $c->qty_required * $unitsToProduce;
            $available = (float) $c->comp_stock;
            if ($available < $needed) {
                $missing[] = [
                    'component_id' => $c->component_product_id,
                    'code' => $c->comp_code,
                    'name' => $c->comp_name,
                    'needed' => $needed,
                    'available' => $available,
                    'short' => $needed - $available,
                    'uom' => $c->comp_uom,
                ];
            }
        }

        return [
            'feasible' => empty($missing),
            'missing' => $missing,
            'units' => $unitsToProduce,
        ];
    }
}
