<?php

namespace App\Produksi\Services;

use App\Models\QualityInspection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * QC Inspection workflow service.
 *
 * Checkpoint format: [{name, expected, actual, result: 'pass'|'fail', notes}]
 *
 * Status auto-resolves on finish:
 *   - all pass → passed
 *   - any fail with critical defects → failed
 *   - any fail with minor defects → rework_needed (default if fails > 0 but not blocked)
 */
class QualityInspectionService
{
    public function generateNumber(int $companyId): string
    {
        $year = date('Y');

        return DB::transaction(function () use ($companyId, $year) {
            $last = QualityInspection::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('inspection_number', 'like', "QC-{$year}-%")
                ->orderByDesc('inspection_number')
                ->lockForUpdate()
                ->value('inspection_number');
            $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

            return "QC-{$year}-".str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        });
    }

    public function plan(User $actor, array $data): QualityInspection
    {
        return QualityInspection::create([
            'company_id' => $actor->company_id,
            'inspection_number' => $this->generateNumber($actor->company_id),
            'work_order_id' => $data['work_order_id'] ?? null,
            'product_serial' => $data['product_serial'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'inspection_type' => $data['inspection_type'] ?? 'final',
            'inspection_date' => $data['inspection_date'] ?? now()->toDateString(),
            'inspector_user_id' => $data['inspector_user_id'] ?? $actor->id,
            'status' => 'planned',
            'checkpoints' => $data['checkpoints'] ?? [],
            'total_checks' => count($data['checkpoints'] ?? []),
            'passed_checks' => 0,
            'failed_checks' => 0,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Submit results for an inspection: takes checkpoint results, auto-derive pass/fail counts + status.
     */
    public function submit(User $actor, QualityInspection $insp, array $data): QualityInspection
    {
        return DB::transaction(function () use ($insp, $data) {
            $checkpoints = $data['checkpoints'] ?? $insp->checkpoints ?? [];
            $passed = 0;
            $failed = 0;
            $hasCritical = false;

            foreach ($checkpoints as $cp) {
                $result = $cp['result'] ?? null;
                if ($result === 'pass') {
                    $passed++;
                } elseif ($result === 'fail') {
                    $failed++;
                    if (($cp['severity'] ?? 'minor') === 'critical') {
                        $hasCritical = true;
                    }
                }
            }

            $total = count($checkpoints);
            $status = 'passed';
            if ($failed > 0) {
                $status = $hasCritical ? 'failed' : 'rework_needed';
            }

            $insp->update([
                'checkpoints' => $checkpoints,
                'total_checks' => $total,
                'passed_checks' => $passed,
                'failed_checks' => $failed,
                'status' => $status,
                'defects_found' => $data['defects_found'] ?? $insp->defects_found,
                'corrective_action' => $data['corrective_action'] ?? $insp->corrective_action,
                'notes' => $data['notes'] ?? $insp->notes,
                'verified_by_user_id' => $data['verified_by_user_id'] ?? null,
            ]);

            // ─── Auto-quarantine integration ──────────────────────────────
            // Kalau inspection terhubung ke batch tertentu, sync quarantine flag.
            //   failed / rework_needed → batch.is_quarantined = true (stop consumption)
            //   passed                 → batch.is_quarantined = false (release back)
            if ($insp->batch_id) {
                $batch = \App\Warehouse\Models\InventoryBatch::find($insp->batch_id);
                if ($batch) {
                    $shouldQuarantine = in_array($status, ['failed', 'rework_needed'], true);
                    if ($batch->is_quarantined !== $shouldQuarantine) {
                        $batch->update(['is_quarantined' => $shouldQuarantine]);
                    }
                }
            }

            return $insp->fresh();
        });
    }
}
