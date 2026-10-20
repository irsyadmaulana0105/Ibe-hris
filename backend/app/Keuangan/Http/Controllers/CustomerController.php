<?php

namespace App\Keuangan\Http\Controllers;

use App\Keuangan\Models\Customer;
use App\Http\Controllers\Api\V1\ApiController;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends ApiController
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'finance.module.access');
        $q = $request->string('q')->toString();
        $rows = Customer::query()
            ->where('company_id', $request->user()->company_id)
            ->when($q, fn ($w) => $w->where(function ($x) use ($q) {
                $x->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%");
            }))
            ->orderBy('name')
            ->paginate(50);
        return $this->paginated($rows, $rows->items());
    }

    public function store(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->requireGranularCapability($admin, 'finance.invoice.manage', 'finance.module.access');

        $data = $request->validate([
            'name'           => ['required', 'string', 'max:200'],
            'code'           => ['nullable', 'string', 'max:20'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'email'          => ['nullable', 'email', 'max:150'],
            'phone'          => ['nullable', 'string', 'max:32'],
            'npwp'           => ['nullable', 'string', 'max:20'],
            'address'        => ['nullable', 'string'],
            'city'           => ['nullable', 'string', 'max:100'],
            'province'       => ['nullable', 'string', 'max:100'],
            'postal_code'    => ['nullable', 'string', 'max:10'],
            'notes'          => ['nullable', 'string'],
        ]);
        $data['company_id'] = $admin->company_id;
        if (empty($data['code'])) $data['code'] = $this->generateCode((int) $admin->company_id);

        $row = Customer::create($data);
        $this->audit->log('finance.customer.created', $admin, 'fin_customer', (string) $row->id, ['name' => $row->name]);
        return $this->created($row, "Customer {$row->name} dibuat.");
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireGranularCapability($admin, 'finance.invoice.manage', 'finance.module.access');
        $row = Customer::query()->where('company_id', $admin->company_id)->findOrFail($id);

        $data = $request->validate([
            'name'           => ['sometimes', 'string', 'max:200'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'email'          => ['nullable', 'email', 'max:150'],
            'phone'          => ['nullable', 'string', 'max:32'],
            'npwp'           => ['nullable', 'string', 'max:20'],
            'address'        => ['nullable', 'string'],
            'city'           => ['nullable', 'string', 'max:100'],
            'province'       => ['nullable', 'string', 'max:100'],
            'postal_code'    => ['nullable', 'string', 'max:10'],
            'notes'          => ['nullable', 'string'],
            'is_active'      => ['sometimes', 'boolean'],
        ]);
        $row->update($data);
        $this->audit->log('finance.customer.updated', $admin, 'fin_customer', (string) $row->id, $data);
        return $this->success($row->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireGranularCapability($admin, 'finance.invoice.manage', 'finance.module.access');
        $row = Customer::query()->where('company_id', $admin->company_id)->findOrFail($id);
        if ($row->invoices()->exists()) {
            return response()->json(['message' => "Customer {$row->name} masih punya invoice. Set inactive saja."], 422);
        }
        $row->delete();
        return $this->success(message: 'Customer dihapus.');
    }

    private function generateCode(int $companyId): string
    {
        return DB::transaction(function () use ($companyId) {
            $last = Customer::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('code', 'like', 'CUST-%')
                ->orderByDesc('code')
                ->lockForUpdate()
                ->value('code');
            $seq = 1;
            if ($last) $seq = ((int) substr($last, 5)) + 1;
            return 'CUST-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        });
    }
}
