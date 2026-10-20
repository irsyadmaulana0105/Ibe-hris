<?php

namespace App\Warehouse\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Services\AuditLogService;
use App\Warehouse\Models\FixedAsset;
use App\Warehouse\Services\FixedAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FixedAssetController extends ApiController
{
    public function __construct(
        private readonly FixedAssetService $fa,
        private readonly AuditLogService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'finance.module.access');

        $q = $request->string('q')->toString();
        $category = $request->string('category')->toString();
        $status = $request->string('status')->toString();

        $rows = FixedAsset::query()
            ->where('company_id', $user->company_id)
            ->when($q, fn ($x) => $x->where(function ($y) use ($q) {
                $y->where('name', 'like', "%{$q}%")
                    ->orWhere('asset_tag', 'like', "%{$q}%")
                    ->orWhere('serial_number', 'like', "%{$q}%");
            }))
            ->when($category, fn ($x) => $x->where('category', $category))
            ->when($status, fn ($x) => $x->where('status', $status))
            ->with('assignedTo:id,name,email')
            ->orderByDesc('created_at')
            ->paginate(50);

        return $this->paginated($rows, $rows->items());
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'finance.module.access');

        $asset = FixedAsset::query()
            ->where('company_id', $user->company_id)
            ->with(['assignedTo:id,name,email', 'depreciations'])
            ->findOrFail($id);

        return $this->success($asset);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'finance.module.access');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'category' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'acquisition_date' => ['required', 'date'],
            'acquisition_cost' => ['required', 'numeric', 'gt:0'],
            'currency' => ['nullable', 'string', 'size:3', 'exists:currencies,code'],
            'salvage_value' => ['nullable', 'numeric', 'min:0'],
            'useful_life_months' => ['required', 'integer', 'min:1', 'max:600'],
            'depreciation_method' => ['nullable', 'in:straight_line,declining_balance,none'],
            'location' => ['nullable', 'string', 'max:200'],
            'assigned_to_user_id' => ['nullable', 'string', 'size:26'],
            'invoice_no' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $asset = $this->fa->register($user, $data);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->audit->log('fixed_asset.created', $user, 'fixed_asset', (string) $asset->id, [
            'tag' => $asset->asset_tag, 'name' => $asset->name,
        ]);

        return $this->created($asset, "Asset {$asset->asset_tag} terdaftar.");
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'finance.module.access');

        $asset = FixedAsset::query()->where('company_id', $user->company_id)->findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'category' => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:200'],
            'assigned_to_user_id' => ['nullable', 'string', 'size:26'],
            'status' => ['sometimes', 'in:active,in_repair,retired,disposed'],
            'notes' => ['nullable', 'string'],
        ]);
        $asset->update($data);

        return $this->success($asset->fresh());
    }

    public function postDepreciation(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'finance.module.access');

        $data = $request->validate([
            'period' => ['nullable', 'date'],
        ]);

        $period = isset($data['period']) ? \Carbon\Carbon::parse($data['period']) : null;
        $result = $this->fa->postMonthlyDepreciation($user, $period);

        $this->audit->log('fixed_asset.depreciation.posted', $user, 'fixed_asset', null, $result);

        return $this->success($result, "Depreciation posted: {$result['posted']} assets ({$result['skipped']} skip).");
    }

    public function dispose(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'finance.module.access');

        $asset = FixedAsset::query()->where('company_id', $user->company_id)->findOrFail($id);
        $data = $request->validate([
            'disposal_date' => ['nullable', 'date'],
            'disposal_value' => ['nullable', 'numeric', 'min:0'],
            'disposal_method' => ['nullable', 'in:sold,donated,scrapped,lost'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $updated = $this->fa->dispose($user, $asset, $data);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->success($updated, "Asset {$asset->asset_tag} disposed.");
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'finance.module.access');

        $asset = FixedAsset::query()->where('company_id', $user->company_id)->findOrFail($id);
        if ($asset->accumulated_depreciation > 0) {
            return response()->json(['message' => 'Asset sudah ada depreciation. Dispose saja, jangan hapus.'], 422);
        }
        $asset->delete();

        return $this->success(message: 'Asset dihapus.');
    }
}
