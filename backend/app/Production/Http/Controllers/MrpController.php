<?php

namespace App\Production\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Bom;
use App\Production\Services\BomService;
use App\Production\Services\InventoryAlertService;
use App\Production\Services\MrpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only endpoints untuk planning module:
 *   - low stock alerts
 *   - MRP suggestion (WO + PO)
 *   - BOM explosion preview
 *
 * Tidak bikin WO/PO langsung - user PIC harus approve via existing WO/PO
 * controller dengan payload yang di-prefill dari sini.
 */
class MrpController extends ApiController
{
    public function __construct(
        private readonly InventoryAlertService $alertSvc,
        private readonly MrpService $mrpSvc,
        private readonly BomService $bomSvc,
    ) {}

    /** GET /production/inventory/alerts - list produk di bawah reorder */
    public function alerts(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        return $this->success([
            'items' => $this->alertSvc->lowStockReport($companyId),
            'summary' => $this->alertSvc->summary($companyId),
        ]);
    }

    /** GET /production/mrp/suggestions - full MRP package */
    public function mrp(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        return $this->success($this->mrpSvc->suggest($companyId));
    }

    /**
     * POST /production/boms/{bom}/explode - multi-level BOM explosion preview.
     * Body: { units: number }
     */
    public function explode(Request $request, Bom $bom): JsonResponse
    {
        $data = $request->validate([
            'units' => ['required', 'numeric', 'min:0.0001'],
        ]);
        if ($bom->company_id !== $request->user()->company_id) {
            abort(403);
        }

        return $this->success($this->bomSvc->explode($bom, (float) $data['units']));
    }

    /**
     * GET /production/boms/{bom}/can-produce-deep?units=N - feasibility check
     * dengan multi-level explosion (lebih akurat daripada canProduce flat).
     */
    public function canProduceDeep(Request $request, Bom $bom): JsonResponse
    {
        $units = (float) $request->query('units', 1);
        if ($bom->company_id !== $request->user()->company_id) {
            abort(403);
        }

        return $this->success($this->bomSvc->canProduceDeep($bom, $units));
    }
}
