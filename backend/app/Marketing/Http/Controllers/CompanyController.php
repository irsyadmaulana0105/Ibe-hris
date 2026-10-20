<?php

namespace App\Marketing\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Marketing\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.view');

        $q = $request->string('q')->toString();
        $rows = Company::query()
            ->where('company_id', $user->company_id)
            ->when($q, fn ($w) => $w->where('name', 'like', "%{$q}%"))
            ->orderBy('name')
            ->paginate(50);

        return $this->paginated($rows, $rows->items());
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.view');

        $row = Company::query()
            ->where('company_id', $user->company_id)
            ->with(['contacts', 'deals.stage'])
            ->findOrFail($id);

        return $this->success($row);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.manage');

        $data = $this->validateData($request);
        $data['company_id'] = $user->company_id;
        $data['owner_id'] = $data['owner_id'] ?? $user->id;

        $row = Company::create($data);

        return $this->created($row, "Company {$row->name} dibuat.");
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.manage');

        $row = Company::query()->where('company_id', $user->company_id)->findOrFail($id);
        $row->update($this->validateData($request, true));

        return $this->success($row->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.admin');

        $row = Company::query()->where('company_id', $user->company_id)->findOrFail($id);
        $row->delete();

        return $this->success(message: 'Company dihapus.');
    }

    private function validateData(Request $request, bool $isUpdate = false): array
    {
        $rule = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$rule, 'string', 'max:200'],
            'industry' => ['nullable', 'string', 'max:100'],
            'size' => ['nullable', 'in:micro,small,medium,large,enterprise'],
            'website' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
    }
}
