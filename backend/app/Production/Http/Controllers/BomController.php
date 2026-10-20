<?php

namespace App\Production\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Bom;
use App\Models\Product;
use App\Production\Services\BomService;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BomController extends ApiController
{
    public function __construct(
        private readonly BomService $bom,
        private readonly AuditLogService $audit,
    ) {}

    /** GET /admin/boms?product_id=X */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'production.view');

        $productId = $request->integer('product_id');
        $rows = Bom::query()
            ->where('company_id', $user->company_id)
            ->when($productId, fn ($x) => $x->where('product_id', $productId))
            ->with(['product:id,code,name,product_type'])
            ->withCount('components')
            ->orderByDesc('is_active')
            ->orderByDesc('created_at')
            ->get();

        return $this->success($rows);
    }

    /** GET /admin/boms/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'production.view');

        $bom = Bom::query()
            ->where('company_id', $user->company_id)
            ->with(['product:id,code,name,product_type,unit_of_measure'])
            ->with(['components.component:id,code,name,unit_of_measure,unit_cost,total_stock_cached'])
            ->findOrFail($id);

        // Live cost recompute (cached value bisa stale kalau ada component cost update)
        $this->bom->refreshTotalCost($bom);

        return $this->success($bom->fresh(['product', 'components.component']));
    }

    /** POST /admin/boms - create new BOM (typically v1 first time, atau v2+ untuk revision) */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'production.manage');

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'version' => ['nullable', 'string', 'max:20'],
            'name' => ['nullable', 'string', 'max:200'],
            'labor_cost' => ['nullable', 'numeric', 'min:0'],
            'overhead_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        // Verify product is producible (finished_good or sub_assembly)
        $product = Product::query()
            ->where('id', $data['product_id'])
            ->where('company_id', $user->company_id)
            ->firstOrFail();
        if (! in_array($product->product_type, ['finished_good', 'sub_assembly'])) {
            return response()->json([
                'message' => 'Hanya finished_good atau sub_assembly yang bisa punya BOM.',
            ], 422);
        }

        // Auto-version: hitung version BOM existing dan increment
        if (empty($data['version'])) {
            $count = Bom::query()
                ->where('company_id', $user->company_id)
                ->where('product_id', $data['product_id'])
                ->count();
            $data['version'] = 'v'.($count + 1);
        }

        $data['company_id'] = $user->company_id;
        $data['created_by'] = $user->id;
        $data['is_active'] = false; // create as inactive, manual activate

        $bom = Bom::create($data);

        $this->audit->log('bom.created', $user, 'bom', (string) $bom->id, [
            'product_id' => $product->id,
            'version' => $bom->version,
        ]);

        return $this->created($bom->load(['product:id,code,name', 'components']), "BOM {$bom->version} dibuat.");
    }

    /** PUT /admin/boms/{id} - update header (labor cost, overhead, notes) */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'production.manage');

        $bom = Bom::query()->where('company_id', $user->company_id)->findOrFail($id);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:200'],
            'labor_cost' => ['nullable', 'numeric', 'min:0'],
            'overhead_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $bom->update($data);
        $this->bom->refreshTotalCost($bom);

        return $this->success($bom->fresh(['components']));
    }

    /** PUT /admin/boms/{id}/components - bulk replace component list */
    public function replaceComponents(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'production.manage');

        $bom = Bom::query()->where('company_id', $user->company_id)->findOrFail($id);

        $data = $request->validate([
            'components' => ['required', 'array'],
            'components.*.component_product_id' => ['required', 'integer', 'exists:products,id'],
            'components.*.qty_required' => ['required', 'numeric', 'gt:0'],
            'components.*.unit_of_measure' => ['nullable', 'string', 'max:20'],
            'components.*.notes' => ['nullable', 'string'],
        ]);

        $updated = $this->bom->replaceComponents($user, $bom, $data['components']);
        $this->audit->log('bom.components.replaced', $user, 'bom', (string) $bom->id, [
            'count' => count($data['components']),
        ]);

        return $this->success($updated, 'Components BOM diperbarui.');
    }

    /** POST /admin/boms/{id}/activate */
    public function activate(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'production.manage');

        $bom = Bom::query()->where('company_id', $user->company_id)->findOrFail($id);
        if ($bom->components()->doesntExist()) {
            return response()->json(['message' => 'BOM tidak punya component. Tambahkan dulu.'], 422);
        }
        $this->bom->activate($bom);
        $this->audit->log('bom.activated', $user, 'bom', (string) $bom->id, []);

        return $this->success($bom->fresh(), "BOM {$bom->version} diaktifkan.");
    }

    /** POST /admin/boms/{id}/can-produce - feasibility check */
    public function canProduce(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'production.view');

        $bom = Bom::query()->where('company_id', $user->company_id)->findOrFail($id);
        $qty = (int) $request->integer('qty', 1);
        $result = $this->bom->canProduce($bom, $qty);

        return $this->success($result);
    }

    /** DELETE /admin/boms/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'production.admin');

        $bom = Bom::query()->where('company_id', $user->company_id)->findOrFail($id);
        if ($bom->is_active) {
            return response()->json(['message' => 'BOM aktif tidak bisa dihapus. Activate version lain dulu.'], 422);
        }
        $bom->delete();

        return $this->success(message: 'BOM dihapus.');
    }
}
