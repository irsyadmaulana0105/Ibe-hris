<?php

namespace App\Surat\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Surat\Models\CompanyBank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyBankController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->requireGranularCapability(
            $request->user(),
            'finance.company_bank.view',
            'admin.access',
            'Company bank read access required.',
            $this->authorizationContext($request, 'view'),
        );

        $rows = CompanyBank::query()
            ->where('company_id', $request->user()->company_id)
            ->orderByDesc('is_default')
            ->orderBy('display_order')
            ->orderBy('bank_name')
            ->get();

        return $this->success($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireGranularCapability(
            $request->user(),
            'finance.company_bank.manage',
            'admin.access',
            'Company bank management access required.',
            $this->authorizationContext($request, 'create'),
        );

        $data = $request->validate($this->rules());

        return DB::transaction(function () use ($data, $request) {
            if (($data['is_default'] ?? false)) {
                CompanyBank::where('company_id', $request->user()->company_id)
                    ->update(['is_default' => false]);
            }
            $bank = CompanyBank::create(array_merge($data, [
                'company_id' => $request->user()->company_id,
                'is_active' => $data['is_active'] ?? true,
            ]));

            return $this->created($bank);
        });
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->requireGranularCapability(
            $request->user(),
            'finance.company_bank.manage',
            'admin.access',
            'Company bank management access required.',
            $this->authorizationContext($request, 'update', $id),
        );

        $bank = CompanyBank::where('id', $id)
            ->where('company_id', $request->user()->company_id)
            ->firstOrFail();
        $data = $request->validate($this->rules(true));

        return DB::transaction(function () use ($bank, $data, $request) {
            if (($data['is_default'] ?? false) && ! $bank->is_default) {
                CompanyBank::where('company_id', $request->user()->company_id)
                    ->where('id', '!=', $bank->id)
                    ->update(['is_default' => false]);
            }
            $bank->update($data);

            return $this->success($bank);
        });
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->requireGranularCapability(
            $request->user(),
            'finance.company_bank.manage',
            'admin.access',
            'Company bank management access required.',
            $this->authorizationContext($request, 'deactivate', $id),
        );

        $bank = CompanyBank::where('id', $id)
            ->where('company_id', $request->user()->company_id)
            ->firstOrFail();
        if ($bank->is_default) {
            abort(422, 'Tidak bisa hapus default bank. Set default ke bank lain dulu.');
        }
        $bank->update(['is_active' => false]);

        return $this->success(null, 'Bank di-deactivate');
    }

    private function rules(bool $update = false): array
    {
        $req = $update ? 'sometimes' : 'required';

        return [
            'bank_name' => [$req, 'string', 'max:100'],
            'account_number' => [$req, 'string', 'max:50'],
            'account_name' => [$req, 'string', 'max:200'],
            'branch' => ['nullable', 'string', 'max:150'],
            'swift_code' => ['nullable', 'string', 'max:20'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer'],
        ];
    }

    private function authorizationContext(Request $request, string $action, ?int $resourceId = null): array
    {
        return array_filter([
            'company_id' => $request->user()->company_id,
            'resource_type' => 'company_bank',
            'resource_id' => $resourceId,
            'action' => $action,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
