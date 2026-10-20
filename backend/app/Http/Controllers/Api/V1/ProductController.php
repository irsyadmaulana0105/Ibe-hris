<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Product;
use App\Models\ProductSpecField;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Products master + spec template. Capability 'production.manage' untuk
 * create/update, 'production.view' untuk list.
 */
class ProductController extends ApiController
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'production.view');

        $q = $request->string('q')->toString();
        $rows = Product::query()
            ->where('company_id', $user->company_id)
            ->when($q, fn ($w) => $w->where(function ($x) use ($q) {
                $x->where('name', 'like', "%{$q}%")
                  ->orWhere('code', 'like', "%{$q}%")
                  ->orWhere('model_number', 'like', "%{$q}%");
            }))
            ->orderBy('name')
            ->paginate(50);

        return $this->paginated($rows, $rows->items());
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'production.view');

        $product = Product::query()
            ->where('company_id', $user->company_id)
            ->with('specFields')
            ->findOrFail($id);

        return $this->success($product);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'production.manage');

        $data = $request->validate([
            'code'            => ['required', 'string', 'max:32'],
            'name'            => ['required', 'string', 'max:200'],
            'category'        => ['nullable', 'string', 'max:100'],
            'model_number'    => ['nullable', 'string', 'max:100'],
            'description'     => ['nullable', 'string'],
            'unit_of_measure' => ['nullable', 'string', 'max:20'],
            'list_price'      => ['nullable', 'numeric', 'min:0'],
            'is_active'       => ['sometimes', 'boolean'],
        ]);
        $data['company_id'] = $user->company_id;
        $data['code']       = strtoupper($data['code']);

        $product = Product::create($data);
        $this->audit->log('product.created', $user, 'product', (string) $product->id, ['code' => $product->code]);

        return $this->created($product, "Product {$product->code} dibuat.");
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'production.manage');

        $product = Product::query()->where('company_id', $user->company_id)->findOrFail($id);

        $data = $request->validate([
            'name'            => ['sometimes', 'string', 'max:200'],
            'category'        => ['nullable', 'string', 'max:100'],
            'model_number'    => ['nullable', 'string', 'max:100'],
            'description'     => ['nullable', 'string'],
            'unit_of_measure' => ['nullable', 'string', 'max:20'],
            'list_price'      => ['nullable', 'numeric', 'min:0'],
            'is_active'       => ['sometimes', 'boolean'],
        ]);
        $product->update($data);

        return $this->success($product->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'production.admin');

        $product = Product::query()->where('company_id', $user->company_id)->findOrFail($id);
        if ($product->serialNumbers()->exists()) {
            return response()->json(['message' => "Product {$product->code} masih punya serial numbers. Set inactive saja."], 422);
        }
        $product->delete();
        return $this->success(message: 'Product dihapus.');
    }

    /** PUT /admin/products/{id}/spec-fields - bulk replace spec template */
    public function updateSpecFields(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'production.manage');

        $product = Product::query()->where('company_id', $user->company_id)->findOrFail($id);

        $data = $request->validate([
            'fields'                       => ['required', 'array'],
            'fields.*.field_key'           => ['required', 'string', 'max:50'],
            'fields.*.label'               => ['required', 'string', 'max:100'],
            'fields.*.field_type'          => ['required', 'in:text,number,decimal,date,boolean,select'],
            'fields.*.unit'                => ['nullable', 'string', 'max:20'],
            'fields.*.options'             => ['nullable', 'array'],
            'fields.*.is_required'         => ['sometimes', 'boolean'],
            'fields.*.show_on_nameplate'   => ['sometimes', 'boolean'],
            'fields.*.sort_order'          => ['nullable', 'integer'],
            'fields.*.default_value'       => ['nullable', 'string', 'max:200'],
        ]);

        DB::transaction(function () use ($product, $data, $user) {
            // Bulk replace - hapus dulu, insert ulang. Mengikat ke product saja.
            ProductSpecField::where('product_id', $product->id)->delete();

            foreach ($data['fields'] as $i => $f) {
                ProductSpecField::create([
                    'company_id'        => $user->company_id,
                    'product_id'        => $product->id,
                    'field_key'         => $f['field_key'],
                    'label'             => $f['label'],
                    'field_type'        => $f['field_type'],
                    'unit'              => $f['unit'] ?? null,
                    'options'           => $f['options'] ?? null,
                    'is_required'       => $f['is_required'] ?? false,
                    'show_on_nameplate' => $f['show_on_nameplate'] ?? true,
                    'sort_order'        => $f['sort_order'] ?? ($i * 10),
                    'default_value'     => $f['default_value'] ?? null,
                ]);
            }
        });

        return $this->success($product->fresh('specFields'), 'Spec template diperbarui.');
    }
}
