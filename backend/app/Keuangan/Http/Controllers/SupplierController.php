<?php

namespace App\Keuangan\Http\Controllers;

use App\Keuangan\Models\Supplier;
use App\Http\Controllers\Api\V1\ApiController;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierController extends ApiController
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'finance.module.access');
        $q = $request->string('q')->toString();
        $rows = Supplier::query()
            ->where('company_id', $request->user()->company_id)
            ->when($q, fn ($w) => $w->where(function ($x) use ($q) {
                $x->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%");
            }))
            ->orderBy('name')
            ->paginate(50);
        return $this->paginated($rows, $rows->items());
    }

    public function store(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->requireGranularCapability($admin, 'finance.bill.manage', 'finance.module.access');

        $data = $request->validate([
            'name'                => ['required', 'string', 'max:200'],
            'code'                => ['nullable', 'string', 'max:20'],
            'contact_person'      => ['nullable', 'string', 'max:150'],
            'email'               => ['nullable', 'email', 'max:150'],
            'phone'               => ['nullable', 'string', 'max:32'],
            'npwp'                => ['nullable', 'string', 'max:20'],
            'address'             => ['nullable', 'string'],
            'bank_name'           => ['nullable', 'string', 'max:100'],
            'bank_account_number' => ['nullable', 'string', 'max:50'],
            'bank_account_name'   => ['nullable', 'string', 'max:150'],
            'notes'               => ['nullable', 'string'],
        ]);
        $data['company_id'] = $admin->company_id;
        if (empty($data['code'])) $data['code'] = $this->generateCode((int) $admin->company_id);

        $row = Supplier::create($data);
        $this->audit->log('finance.supplier.created', $admin, 'fin_supplier', (string) $row->id, ['name' => $row->name]);
        return $this->created($row, "Supplier {$row->name} dibuat.");
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireGranularCapability($admin, 'finance.bill.manage', 'finance.module.access');
        $row = Supplier::query()->where('company_id', $admin->company_id)->findOrFail($id);

        $data = $request->validate([
            'name'                => ['sometimes', 'string', 'max:200'],
            'contact_person'      => ['nullable', 'string', 'max:150'],
            'email'               => ['nullable', 'email', 'max:150'],
            'phone'               => ['nullable', 'string', 'max:32'],
            'npwp'                => ['nullable', 'string', 'max:20'],
            'address'             => ['nullable', 'string'],
            'bank_name'           => ['nullable', 'string', 'max:100'],
            'bank_account_number' => ['nullable', 'string', 'max:50'],
            'bank_account_name'   => ['nullable', 'string', 'max:150'],
            'notes'               => ['nullable', 'string'],
            'is_active'           => ['sometimes', 'boolean'],
        ]);
        $row->update($data);
        $this->audit->log('finance.supplier.updated', $admin, 'fin_supplier', (string) $row->id, $data);
        return $this->success($row->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireGranularCapability($admin, 'finance.bill.manage', 'finance.module.access');
        $row = Supplier::query()->where('company_id', $admin->company_id)->findOrFail($id);
        if ($row->bills()->exists()) {
            return response()->json(['message' => "Supplier {$row->name} masih punya bill. Set inactive saja."], 422);
        }
        $row->delete();
        return $this->success(message: 'Supplier dihapus.');
    }

    private function generateCode(int $companyId): string
    {
        return DB::transaction(function () use ($companyId) {
            $last = Supplier::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('code', 'like', 'SUPP-%')
                ->orderByDesc('code')
                ->lockForUpdate()
                ->value('code');
            $seq = 1;
            if ($last) $seq = ((int) substr($last, 5)) + 1;
            return 'SUPP-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        });
    }
}
