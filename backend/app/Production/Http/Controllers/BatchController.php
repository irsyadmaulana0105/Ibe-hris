<?php

namespace App\Production\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\InventoryBatch;
use App\Production\Services\BatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BatchController extends ApiController
{
    public function __construct(private readonly BatchService $svc) {}

    public function index(Request $request): JsonResponse
    {
        $q = InventoryBatch::query()
            ->where('company_id', $request->user()->company_id)
            ->with('product:id,code,name,unit_of_measure');
        if ($pid = $request->query('product_id')) {
            $q->where('product_id', $pid);
        }
        if ($request->boolean('with_stock')) {
            $q->whereExists(function ($qq) {
                $qq->select(\DB::raw(1))->from('inventory_batch_stock')
                    ->whereColumn('batch_id', 'inventory_batches.id')
                    ->where('qty_on_hand', '>', 0);
            });
        }
        $p = $q->orderByDesc('received_at')->paginate(50);
        $items = $p->getCollection()->map(fn ($b) => [
            'id' => $b->id,
            'batch_code' => $b->batch_code,
            'product_id' => $b->product_id,
            'product' => $b->product ? ['code' => $b->product->code, 'name' => $b->product->name, 'unit_of_measure' => $b->product->unit_of_measure] : null,
            'received_at' => $b->received_at,
            'expires_at' => $b->expires_at,
            'supplier_lot' => $b->supplier_lot,
            'unit_cost' => $b->unit_cost,
            'is_quarantined' => (bool) $b->is_quarantined,
            'total_on_hand' => $b->totalOnHand(),
        ]);

        return $this->paginated($p, $items);
    }

    public function show(Request $request, InventoryBatch $batch): JsonResponse
    {
        $this->authorize($request, $batch);

        return $this->success($batch->load(['product', 'stocks.warehouse']));
    }

    /** Manual receive (selain auto-receive dari PO) */
    public function receive(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'qty' => ['required', 'numeric', 'min:0.0001'],
            'batch_code' => ['required', 'string', 'max:100'],
            'received_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'supplier_lot' => ['nullable', 'string'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);
        $batch = $this->svc->receive(
            $request->user(),
            $data['product_id'],
            $data['warehouse_id'],
            (float) $data['qty'],
            $data['batch_code'],
            $data,
        );

        return $this->created($batch);
    }

    public function trace(Request $request, InventoryBatch $batch): JsonResponse
    {
        $this->authorize($request, $batch);

        return $this->success($this->svc->trace($batch));
    }

    public function expiring(Request $request): JsonResponse
    {
        $days = (int) $request->query('days_ahead', 30);

        return $this->success([
            'days_ahead' => $days,
            'items' => $this->svc->expiringBatches($request->user()->company_id, $days),
        ]);
    }

    private function authorize(Request $request, InventoryBatch $batch): void
    {
        if ($batch->company_id !== $request->user()->company_id) {
            abort(403);
        }
    }
}
