<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\SalaryAdvanceResource;
use App\Services\SalaryAdvanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalaryAdvanceController extends ApiController
{
    public function __construct(private readonly SalaryAdvanceService $service) {}

    /** GET /api/v1/salary-advances */
    public function index(Request $request): JsonResponse
    {
        $advances = $this->service->listForUser($request->user());

        return $this->paginated($advances, SalaryAdvanceResource::collection($advances));
    }

    /** POST /api/v1/salary-advances */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:10000'],
            'purpose' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $advance = $this->service->submit($request->user(), $data);

        return $this->created(new SalaryAdvanceResource($advance), __('Salary advance request submitted.'));
    }

    /** DELETE /api/v1/salary-advances/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->service->cancel($request->user(), $id);

        return $this->success(message: __('Request cancelled.'));
    }

    /** GET /api/v1/admin/salary-advances */
    public function adminIndex(Request $request): JsonResponse
    {
        $this->requireAnyCapability($request->user(), ['salary_advance.manage', 'payroll.manage'], 'Salary advance access required.');
        $advances = $this->service->listForAdmin(
            $request->user(),
            $request->only(['status', 'month', 'division', 'search'])
        );

        return $this->paginated($advances, SalaryAdvanceResource::collection($advances), [
            'pending_count' => $this->service->pendingCount($request->user()->company_id),
        ]);
    }

    /** GET /api/v1/admin/salary-advances/{id} */
    public function adminShow(Request $request, int $id): JsonResponse
    {
        $this->requireAnyCapability($request->user(), ['salary_advance.manage', 'payroll.manage'], 'Salary advance access required.');

        return $this->success(new SalaryAdvanceResource($this->service->detail($request->user(), $id)));
    }

    /** POST /api/v1/admin/salary-advances/{id}/approve */
    public function approve(Request $request, int $id): JsonResponse
    {
        $advance = $this->service->detail($request->user(), $id);
        $this->requireCapability($request->user(), 'salary_advance.manage', 'Salary advance management access required.', [
            'company_id' => $advance->company_id,
            'resource_type' => 'salary_advance',
            'resource_id' => $advance->id,
            'amount' => $advance->amount,
            'maker_id' => $advance->user_id,
            'irreversible' => true,
            'action' => 'approve',
            'status' => $advance->status,
        ]);
        $request->validate(['notes' => ['nullable', 'string', 'max:500']]);
        $advance = $this->service->approve($request->user(), $id, $request->notes);

        return $this->success(new SalaryAdvanceResource($advance), __('Salary advance approved.'));
    }

    /** POST /api/v1/admin/salary-advances/{id}/reject */
    public function reject(Request $request, int $id): JsonResponse
    {
        $advance = $this->service->detail($request->user(), $id);
        $this->requireCapability($request->user(), 'salary_advance.manage', 'Salary advance management access required.', [
            'company_id' => $advance->company_id,
            'resource_type' => 'salary_advance',
            'resource_id' => $advance->id,
            'amount' => $advance->amount,
            'maker_id' => $advance->user_id,
            'irreversible' => true,
            'action' => 'reject',
            'status' => $advance->status,
        ]);
        $request->validate(['notes' => ['nullable', 'string', 'max:500']]);
        $advance = $this->service->reject($request->user(), $id, $request->notes);

        return $this->success(new SalaryAdvanceResource($advance), __('Salary advance rejected.'));
    }

    /** POST /api/v1/admin/salary-advances/{id}/settle */
    public function settle(Request $request, int $id): JsonResponse
    {
        $advance = $this->service->detail($request->user(), $id);
        $this->requireCapability($request->user(), 'payroll.manage', 'Finance settlement access required.', [
            'company_id' => $advance->company_id,
            'resource_type' => 'salary_advance',
            'resource_id' => $advance->id,
            'amount' => $advance->amount,
            'maker_id' => $advance->hr_approved_by ?? $advance->approved_by,
            'irreversible' => true,
            'action' => 'settle',
            'status' => $advance->status,
        ]);
        $data = $request->validate([
            'reference' => ['required', 'string', 'min:3', 'max:100'],
        ]);

        $advance = $this->service->settle($request->user(), $id, $data['reference']);

        return $this->success(new SalaryAdvanceResource($advance), __('Salary advance settled.'));
    }

    public function adminDestroy(Request $request, int $id): JsonResponse
    {
        $advance = $this->service->detail($request->user(), $id);
        $this->requireCapability($request->user(), 'payroll.manage', 'Finance access required.', [
            'company_id' => $advance->company_id,
            'resource_type' => 'salary_advance',
            'resource_id' => $advance->id,
            'amount' => $advance->amount,
            'irreversible' => true,
            'action' => 'delete',
            'status' => $advance->status,
        ]);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->service->adminDelete($request->user(), $id, $validated['reason'] ?? null);

        return $this->success(message: __('Pengajuan kasbon dihapus.'));
    }
}
