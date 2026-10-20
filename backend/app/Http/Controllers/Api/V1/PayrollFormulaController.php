<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PayrollFormulaComponent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayrollFormulaController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'payroll.manage', 'Payroll formula access required.');
        return $this->success(
            PayrollFormulaComponent::query()->orderBy('category')->orderBy('sort_order')->orderBy('id')->get(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'payroll.manage', 'Payroll formula access required.');
        $data = $request->validate([
            'name'             => ['required', 'string', 'max:100'],
            'category'         => ['required', 'string', Rule::in(['earning', 'deduction'])],
            'formula'          => ['required', 'string', 'max:500'],
            'method'           => ['nullable', 'string', Rule::in(['gross', 'net', 'gross_up'])],
            'taxable'          => ['nullable', 'boolean'],
            'sort_order'       => ['nullable', 'integer', 'min:0'],
            'min_working_days' => ['nullable', 'integer', 'min:0', 'max:31'],
        ]);
        return $this->created(PayrollFormulaComponent::create($data), 'Komponen ditambahkan.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'payroll.manage', 'Payroll formula access required.');
        $component = PayrollFormulaComponent::findOrFail($id);
        $data = $request->validate([
            'name'             => ['sometimes', 'string', 'max:100'],
            'category'         => ['sometimes', 'string', Rule::in(['earning', 'deduction'])],
            'formula'          => ['sometimes', 'string', 'max:500'],
            'method'           => ['sometimes', 'string', Rule::in(['gross', 'net', 'gross_up'])],
            'taxable'          => ['sometimes', 'boolean'],
            'sort_order'       => ['sometimes', 'integer', 'min:0'],
            'min_working_days' => ['nullable', 'integer', 'min:0', 'max:31'],
        ]);
        $component->update($data);
        return $this->success($component->fresh(), 'Komponen diperbarui.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'payroll.manage', 'Payroll formula access required.');
        PayrollFormulaComponent::findOrFail($id)->delete();
        return $this->success(message: 'Komponen dihapus.');
    }
}
