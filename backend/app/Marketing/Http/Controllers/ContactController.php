<?php

namespace App\Marketing\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Marketing\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.view');

        $q = $request->string('q')->toString();
        $crmCompanyId = $request->integer('crm_company_id');

        $rows = Contact::query()
            ->where('company_id', $user->company_id)
            ->when($crmCompanyId, fn ($w) => $w->where('crm_company_id', $crmCompanyId))
            ->when($q, fn ($w) => $w->where(function ($x) use ($q) {
                $x->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            }))
            ->orderBy('first_name')
            ->paginate(50);

        return $this->paginated($rows, $rows->items());
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.manage');

        $data = $this->validateData($request);
        $data['company_id'] = $user->company_id;
        $data['owner_id'] = $data['owner_id'] ?? $user->id;

        $row = Contact::create($data);

        return $this->created($row, "Contact {$row->full_name} dibuat.");
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.manage');

        $row = Contact::query()->where('company_id', $user->company_id)->findOrFail($id);
        $row->update($this->validateData($request, true));

        return $this->success($row->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'crm.admin');

        $row = Contact::query()->where('company_id', $user->company_id)->findOrFail($id);
        $row->delete();

        return $this->success(message: 'Contact dihapus.');
    }

    private function validateData(Request $request, bool $isUpdate = false): array
    {
        $rule = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'first_name' => [$rule, 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'position' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'linkedin' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string'],
            'is_primary' => ['sometimes', 'boolean'],
            'crm_company_id' => ['nullable', 'integer', 'exists:crm_companies,id'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
    }
}
