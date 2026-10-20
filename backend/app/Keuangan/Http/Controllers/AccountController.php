<?php

namespace App\Keuangan\Http\Controllers;

use App\Keuangan\Models\Account;
use App\Keuangan\Services\ChartOfAccountsSeeder;
use App\Http\Controllers\Api\V1\ApiController;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Chart of Accounts CRUD + seed default Indonesian CoA.
 */
class AccountController extends ApiController
{
    public function __construct(private readonly AuditLogService $audit) {}

    /** GET /api/v1/finance/accounts */
    public function index(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'finance.module.access');

        $rows = Account::query()
            ->where('company_id', $request->user()->company_id)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'normal_balance', 'parent_id', 'is_header', 'is_active']);

        return $this->success($rows);
    }

    /** POST /api/v1/finance/accounts */
    public function store(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->requireGranularCapability($admin, 'finance.journal.create', 'finance.module.access');

        $data = $request->validate([
            'code'           => ['required', 'string', 'max:20',
                Rule::unique('fin_accounts', 'code')->where('company_id', $admin->company_id)],
            'name'           => ['required', 'string', 'max:200'],
            'type'           => ['required', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense'])],
            'normal_balance' => ['nullable', Rule::in(['debit', 'credit'])],
            'parent_id'      => ['nullable', 'integer', 'exists:fin_accounts,id'],
            'is_header'      => ['nullable', 'boolean'],
            'description'    => ['nullable', 'string', 'max:1000'],
        ]);
        $data['company_id'] = $admin->company_id;
        $data['normal_balance'] = $data['normal_balance'] ?? Account::defaultNormalBalance($data['type']);

        $acc = Account::create($data);

        $this->audit->log('finance.account.created', $admin, 'fin_account', (string) $acc->id, ['code' => $acc->code, 'name' => $acc->name]);

        return $this->created($acc, "Akun {$acc->code} {$acc->name} dibuat.");
    }

    /** PUT /api/v1/finance/accounts/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireGranularCapability($admin, 'finance.journal.create', 'finance.module.access');

        $acc = Account::query()->where('company_id', $admin->company_id)->findOrFail($id);

        $data = $request->validate([
            'code'           => ['sometimes', 'string', 'max:20',
                Rule::unique('fin_accounts', 'code')->where('company_id', $admin->company_id)->ignore($acc->id)],
            'name'           => ['sometimes', 'string', 'max:200'],
            'normal_balance' => ['sometimes', Rule::in(['debit', 'credit'])],
            'parent_id'      => ['nullable', 'integer', 'exists:fin_accounts,id'],
            'is_header'      => ['sometimes', 'boolean'],
            'is_active'      => ['sometimes', 'boolean'],
            'description'    => ['nullable', 'string', 'max:1000'],
        ]);
        $acc->update($data);

        $this->audit->log('finance.account.updated', $admin, 'fin_account', (string) $acc->id, ['changes' => $data]);

        return $this->success($acc->fresh(), 'Akun diperbarui.');
    }

    /** DELETE /api/v1/finance/accounts/{id} - hanya kalau tidak ada lines */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireGranularCapability($admin, 'finance.journal.create', 'finance.module.access');

        $acc = Account::query()->where('company_id', $admin->company_id)->findOrFail($id);
        $usage = $acc->lines()->count();
        if ($usage > 0) {
            return response()->json(['message' => "Akun {$acc->code} masih dipakai di {$usage} baris jurnal."], 422);
        }
        if ($acc->children()->count() > 0) {
            return response()->json(['message' => "Akun {$acc->code} masih punya sub-akun."], 422);
        }

        $this->audit->log('finance.account.deleted', $admin, 'fin_account', (string) $acc->id, ['code' => $acc->code]);

        $acc->delete();
        return $this->success(message: 'Akun dihapus.');
    }

    /** POST /api/v1/finance/accounts/seed - install default CoA Indonesia */
    public function seed(Request $request, ChartOfAccountsSeeder $seeder): JsonResponse
    {
        $admin = $request->user();
        $this->requireGranularCapability($admin, 'finance.journal.create', 'finance.module.access');

        $count = $seeder->seedForCompany((int) $admin->company_id);

        $this->audit->log('finance.coa.seeded', $admin, 'fin_account', null, ['count' => $count]);

        return $this->success(['count' => $count], "Chart of Accounts default ({$count} akun) di-install.");
    }
}
