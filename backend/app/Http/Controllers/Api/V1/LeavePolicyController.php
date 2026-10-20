<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\LeaveHoliday;
use App\Models\LeavePolicyGlobal;
use App\Models\LeavePolicyQuota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeavePolicyController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'leave_policy.manage', 'Leave policy access required.');

        $companyId = $request->user()->company_id;

        return $this->success([
            'quotas'    => LeavePolicyQuota::query()->orderBy('id')->get(),
            'holidays'  => LeaveHoliday::query()->orderBy('date')->get(),
            'globals'   => LeavePolicyGlobal::query()->firstOrCreate(['company_id' => $companyId]),
        ]);
    }

    public function storeQuota(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'leave_policy.manage', 'Leave policy access required.');

        $data = $request->validate([
            'type_key'  => ['required', 'string', 'max:50'],
            'name'      => ['required', 'string', 'max:100'],
            'permanent' => ['nullable', 'integer', 'min:0', 'max:365'],
            'contract'  => ['nullable', 'integer', 'min:0', 'max:365'],
            'intern'    => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        return $this->created(LeavePolicyQuota::create($data), 'Jenis cuti ditambahkan.');
    }

    public function updateQuota(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'leave_policy.manage', 'Leave policy access required.');
        $quota = LeavePolicyQuota::findOrFail($id);
        $data = $request->validate([
            'name'      => ['sometimes', 'string', 'max:100'],
            'permanent' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'contract'  => ['sometimes', 'integer', 'min:0', 'max:365'],
            'intern'    => ['sometimes', 'integer', 'min:0', 'max:365'],
        ]);
        $quota->update($data);
        return $this->success($quota->fresh(), 'Kuota diperbarui.');
    }

    public function destroyQuota(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'leave_policy.manage', 'Leave policy access required.');
        LeavePolicyQuota::findOrFail($id)->delete();
        return $this->success(message: 'Kuota dihapus.');
    }

    public function storeHoliday(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'leave_policy.manage', 'Leave policy access required.');
        $data = $request->validate([
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['nullable', 'string', 'max:50'],
        ]);
        return $this->created(LeaveHoliday::create($data), 'Libur ditambahkan.');
    }

    public function destroyHoliday(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'leave_policy.manage', 'Leave policy access required.');
        LeaveHoliday::findOrFail($id)->delete();
        return $this->success(message: 'Libur dihapus.');
    }

    public function updateGlobals(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'leave_policy.manage', 'Leave policy access required.');
        $data = $request->validate([
            'carry_over_enabled'   => ['sometimes', 'boolean'],
            'max_carry'            => ['sometimes', 'integer', 'min:0', 'max:30'],
            'auto_approve_enabled' => ['sometimes', 'boolean'],
            'auto_approve_days'    => ['sometimes', 'integer', 'min:0', 'max:60'],
        ]);
        $globals = LeavePolicyGlobal::query()->firstOrCreate(['company_id' => $request->user()->company_id]);
        $globals->update($data);
        return $this->success($globals->fresh(), 'Setting global disimpan.');
    }
}
