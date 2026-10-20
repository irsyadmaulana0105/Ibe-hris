<?php

namespace App\Production\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Production\Services\StockCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockCountController extends ApiController
{
    public function __construct(private readonly StockCountService $svc) {}

    public function index(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'inventory.view', 'Akses stock count diperlukan.');
        $q = StockCount::query()
            ->where('company_id', $request->user()->company_id)
            ->with('warehouse')
            ->withCount('lines')
            ->orderByDesc('count_date');
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($wh = $request->query('warehouse_id')) {
            $q->where('warehouse_id', $wh);
        }
        $p = $q->paginate(50);
        $items = $p->getCollection()->map(fn ($r) => $this->withVariance($r));

        return $this->paginated($p, $items);
    }

    public function show(Request $request, StockCount $stockCount): JsonResponse
    {
        $this->authorize($request, $stockCount);
        $this->requireCapability($request->user(), 'inventory.view', 'Akses stock count diperlukan.');
        $isBlind = $stockCount->status === 'counting';

        return $this->success($this->withVariance($stockCount->load(['warehouse', 'lines.product']), $isBlind));
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'inventory.manage', 'Akses pengelolaan stock count diperlukan.');
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'count_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'filter' => ['nullable', 'array'],
        ]);

        return $this->created($this->svc->create($request->user(), $data));
    }

    public function start(Request $request, StockCount $stockCount): JsonResponse
    {
        $this->authorize($request, $stockCount);
        $this->requireCapability($request->user(), 'inventory.manage', 'Akses pengelolaan stock count diperlukan.');
        $data = $request->validate([
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer'],
        ]);
        $result = $this->svc->start($stockCount, $request->user(), $data['product_ids'] ?? null);

        return $this->success($this->withVariance($result, true));
    }

    public function recordCount(Request $request, StockCountLine $line): JsonResponse
    {
        $this->authorize($request, $line->stockCount);
        $this->requireCapability($request->user(), 'inventory.manage', 'Akses pengelolaan stock count diperlukan.');
        $data = $request->validate([
            'counted_qty' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->success(
            $this->svc->recordCount($line, $request->user(), (float) $data['counted_qty'], $data['notes'] ?? null),
        );
    }

    public function post(Request $request, StockCount $stockCount): JsonResponse
    {
        $this->authorize($request, $stockCount);
        $this->requireCapability($request->user(), 'inventory.admin', context: [
            'company_id' => $stockCount->company_id,
            'resource_type' => 'stock_count', 'resource_id' => $stockCount->id,
            'maker_id' => $stockCount->counted_by ?? $stockCount->created_by,
            'irreversible' => true, 'action' => 'post', 'status' => $stockCount->status,
            'scopes' => ['warehouse_id' => $stockCount->warehouse_id],
        ]);
        $data = $request->validate(['notes' => ['nullable', 'string']]);
        $result = $this->svc->post($stockCount, $request->user(), $data['notes'] ?? null);

        return $this->success($this->withVariance($result));
    }

    public function cancel(Request $request, StockCount $stockCount): JsonResponse
    {
        $this->authorize($request, $stockCount);
        $this->requireCapability($request->user(), 'inventory.manage', 'Akses pengelolaan stock count diperlukan.');
        $data = $request->validate(['reason' => ['required', 'string']]);

        return $this->success($this->svc->cancel($stockCount, $request->user(), $data['reason']));
    }

    private function authorize(Request $request, StockCount $count): void
    {
        if ($count->company_id !== $request->user()->company_id) {
            abort(403);
        }
    }

    /**
     * Append variance summary ke response. Kalau $blind = true, system_qty
     * per line di-hide (blind count enforcement).
     */
    private function withVariance(StockCount $count, bool $blind = false): array
    {
        $data = $count->toArray();
        $data['variance'] = $this->svc->variance($count);
        if ($blind && isset($data['lines'])) {
            foreach ($data['lines'] as &$line) {
                // Hide system_qty + variance_qty kalau line belum di-count
                if (($line['status'] ?? '') === 'pending') {
                    $line['system_qty'] = null;
                    $line['variance_qty'] = null;
                }
            }
        }

        return $data;
    }
}
