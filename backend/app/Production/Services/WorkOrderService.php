<?php

namespace App\Production\Services;

use App\Models\Bom;
use App\Models\Product;
use App\Models\ProductSerialNumber;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderComponent;
use App\Models\WorkOrderOutput;
use App\Produksi\Services\ProductionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Manufacturing Work Order - the climax of Phase 1.
 *
 * Status flow:
 *   planned → released → in_progress → completed → closed
 *
 * Saat RELEASE:
 *   - Snapshot active BOM components ke wo_components table
 *   - Consume components dari source warehouse (movement reason=wo_consumption)
 *   - Status → released
 *
 * Saat COMPLETE:
 *   - Generate N serial numbers (1 per unit produced)
 *   - Stock IN finished_good × N ke output warehouse (reason=wo_output)
 *   - Link serial numbers ke WO via wo_outputs
 *   - Status → completed
 *
 * Atomic + rollback safe.
 */
class WorkOrderService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly BomService $bomSvc,
        private readonly ProductionService $production,
    ) {}

    public function generateWoNumber(int $companyId): string
    {
        $year = date('Y');

        return DB::transaction(function () use ($companyId, $year) {
            $last = WorkOrder::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('wo_number', 'like', "WO-{$year}-%")
                ->orderByDesc('wo_number')
                ->lockForUpdate()
                ->value('wo_number');
            $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

            return "WO-{$year}-".str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        });
    }

    /** Create planned WO - snapshot active BOM cost. */
    public function createPlan(User $actor, array $data): WorkOrder
    {
        return DB::transaction(function () use ($actor, $data) {
            $product = Product::query()
                ->where('id', $data['product_id'])
                ->where('company_id', $actor->company_id)
                ->firstOrFail();

            // Find active BOM for this product
            $bom = Bom::query()
                ->where('company_id', $actor->company_id)
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->first();
            if (! $bom) {
                throw new \RuntimeException("Tidak ada BOM aktif untuk produk {$product->code}. Buat & aktifkan BOM dulu.");
            }
            if ($bom->components()->doesntExist()) {
                throw new \RuntimeException("BOM {$bom->version} tidak punya component. Tambahkan dulu di BOM editor.");
            }

            $qtyPlanned = (int) $data['qty_planned'];

            // Snapshot cost
            $material = (float) ($bom->total_cost_cached - $bom->labor_cost - $bom->overhead_cost);
            $totalMaterial = $material * $qtyPlanned;
            $totalLabor = (float) $bom->labor_cost * $qtyPlanned;
            $totalOverhead = (float) $bom->overhead_cost * $qtyPlanned;

            $wo = WorkOrder::create([
                'company_id' => $actor->company_id,
                'wo_number' => $this->generateWoNumber($actor->company_id),
                'product_id' => $product->id,
                'bom_id' => $bom->id,
                'bom_version' => $bom->version,
                'qty_planned' => $qtyPlanned,
                'qty_produced' => 0,
                'source_warehouse_id' => $data['source_warehouse_id'],
                'output_warehouse_id' => $data['output_warehouse_id'],
                'status' => 'planned',
                'planned_start_date' => $data['planned_start_date'] ?? null,
                'planned_complete_date' => $data['planned_complete_date'] ?? null,
                'material_cost_planned' => $totalMaterial,
                'labor_cost_planned' => $totalLabor,
                'overhead_cost_planned' => $totalOverhead,
                'total_cost_planned' => $totalMaterial + $totalLabor + $totalOverhead,
                'notes' => $data['notes'] ?? null,
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'created_by' => $actor->id,
            ]);

            return $wo;
        });
    }

    /**
     * Release WO - snapshot components + consume from inventory.
     * Throws kalau stock tidak cukup (atomic - kalau gagal, rollback semua).
     */
    public function release(WorkOrder $wo, User $actor): WorkOrder
    {
        if ($wo->status !== 'planned') {
            throw new \RuntimeException("Cannot release: status {$wo->status} (must be 'planned')");
        }

        return DB::transaction(function () use ($wo, $actor) {
            $bom = Bom::with('components')->findOrFail($wo->bom_id);

            // Pre-check feasibility (lebih baik gagal cepat sebelum partial consume)
            $check = $this->bomSvc->canProduce($bom, $wo->qty_planned);
            if (! $check['feasible']) {
                $shortList = collect($check['missing'])->map(fn ($m) => "{$m['code']} kurang {$m['short']} {$m['uom']}")->join('; ');
                throw new \RuntimeException("Stock tidak cukup: {$shortList}");
            }

            // Snapshot components + consume
            foreach ($bom->components as $bc) {
                $totalNeeded = (float) $bc->qty_required * $wo->qty_planned;

                WorkOrderComponent::create([
                    'work_order_id' => $wo->id,
                    'component_product_id' => $bc->component_product_id,
                    'qty_per_unit' => $bc->qty_required,
                    'qty_total_planned' => $totalNeeded,
                    'qty_consumed' => $totalNeeded, // for now, assume planned = consumed
                    'unit_cost_snapshot' => $bc->unit_cost_snapshot,
                ]);

                // Consume from inventory (OUT movement, reason=wo_consumption)
                $this->stock->move(
                    $actor,
                    $bc->component_product_id,
                    $wo->source_warehouse_id,
                    -$totalNeeded,
                    'wo_consumption',
                    [
                        'reference_type' => 'WorkOrder',
                        'reference_id' => (string) $wo->id,
                        'notes' => "WO {$wo->wo_number} consume",
                    ],
                );
            }

            $wo->update([
                'status' => 'released',
                'released_at' => now(),
                'released_by' => $actor->id,
            ]);

            return $wo->fresh(['components']);
        });
    }

    /**
     * Complete WO - generate N serial numbers + stock IN finished_good.
     * $spec_values: optional spec values to apply to all generated SNs.
     */
    public function complete(WorkOrder $wo, User $actor, ?array $specValues = null): WorkOrder
    {
        if (! in_array($wo->status, ['released', 'in_progress'])) {
            throw new \RuntimeException("Cannot complete: status {$wo->status} (must be released/in_progress)");
        }

        return DB::transaction(function () use ($wo, $actor, $specValues) {
            $product = Product::findOrFail($wo->product_id);

            // Compute serial number block upfront - avoid race condition di nested transaction.
            // production->generateSerialNumber() pakai DB::transaction sendiri yang dalam nested
            // SAVEPOINT context tidak see uncommitted INSERT dari iteration sebelumnya;
            // jadi kalau dipanggil N kali bisa return same serial number.
            $codeClean = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $product->code));
            $yearMonth = now()->format('Ym');
            $prefix = "IBE-{$codeClean}-{$yearMonth}-";
            $lastSerial = ProductSerialNumber::query()->withoutGlobalScopes()
                ->where('company_id', $actor->company_id)
                ->where('product_code', $product->code)
                ->where('serial_number', 'like', $prefix.'%')
                ->orderByDesc('serial_number')
                ->lockForUpdate()
                ->value('serial_number');
            $startSeq = $lastSerial ? ((int) substr($lastSerial, strlen($prefix))) + 1 : 1;

            $generated = [];
            for ($i = 0; $i < $wo->qty_planned; $i++) {
                $serialString = $prefix.str_pad((string) ($startSeq + $i), 4, '0', STR_PAD_LEFT);

                $sn = ProductSerialNumber::create([
                    'id' => (string) Str::ulid(),
                    'company_id' => $actor->company_id,
                    'product_id' => $product->id,
                    'serial_number' => $serialString,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'spec_values' => $specValues,
                    'status' => 'active',
                    'asset_status' => 'in_warehouse',
                    'asset_location' => $wo->outputWarehouse->name ?? null,
                    'manufactured_at' => now()->toDateString(),
                    'generated_by_user_id' => $actor->id,
                    'notes' => "Auto-generated dari WO {$wo->wo_number}",
                ]);

                WorkOrderOutput::create([
                    'work_order_id' => $wo->id,
                    'serial_number_id' => $sn->id,
                    'produced_at' => now(),
                ]);

                $generated[] = $sn->serial_number;
            }

            // Stock IN finished_good × qty
            $this->stock->move(
                $actor,
                $product->id,
                $wo->output_warehouse_id,
                (float) $wo->qty_planned,
                'wo_output',
                [
                    'unit_cost' => $wo->total_cost_planned / $wo->qty_planned, // per-unit HPP
                    'reference_type' => 'WorkOrder',
                    'reference_id' => (string) $wo->id,
                    'notes' => "WO {$wo->wo_number} output (".count($generated).' units)',
                ],
            );

            $wo->update([
                'status' => 'completed',
                'qty_produced' => $wo->qty_planned,
                'completed_at' => now(),
                'completed_by' => $actor->id,
            ]);

            return $wo->fresh(['components', 'outputs']);
        });
    }

    public function cancel(WorkOrder $wo, User $actor, string $reason): WorkOrder
    {
        if ($wo->status === 'completed' || $wo->status === 'closed') {
            throw new \RuntimeException("Cannot cancel: {$wo->status}");
        }

        return DB::transaction(function () use ($wo, $actor, $reason) {
            // Kalau sudah released, reverse consumption (return komponen ke stock)
            if (in_array($wo->status, ['released', 'in_progress'])) {
                foreach ($wo->components as $wc) {
                    if ($wc->qty_consumed > 0) {
                        $this->stock->move(
                            $actor,
                            $wc->component_product_id,
                            $wo->source_warehouse_id,
                            (float) $wc->qty_consumed,
                            'return_in',
                            [
                                'reference_type' => 'WorkOrder',
                                'reference_id' => (string) $wo->id,
                                'notes' => "WO {$wo->wo_number} CANCELLED - return component",
                            ],
                        );
                    }
                }
            }

            $wo->update([
                'status' => 'cancelled',
                'notes' => trim(($wo->notes ?? '')."\nCANCELLED: {$reason}"),
            ]);

            return $wo->fresh();
        });
    }
}
