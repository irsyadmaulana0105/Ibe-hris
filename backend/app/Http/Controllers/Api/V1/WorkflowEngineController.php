<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\WorkflowRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Read-only helper for the frontend: returns the full ordered list of steps
 * for a given workflow key and whether the current user can act on each
 * step. Used by the request-detail page to show "Step 2 of 3 - waiting on
 * Finance" badges.
 */
class WorkflowEngineController extends ApiController
{
    public function __construct(private readonly WorkflowRouter $router) {}

    public function describe(Request $request): JsonResponse
    {
        $this->requireAnyCapability($request->user(), [
            'leave.manage', 'overtime.manage', 'reimbursement.manage',
            'salary_advance.manage', 'payroll.manage', 'workflow.manage',
        ]);

        $data = $request->validate([
            'key' => ['required', 'string', Rule::in(['leave', 'overtime', 'reimburse', 'kasbon', 'payroll'])],
        ]);

        $info = $this->router->describe($request->user()->company_id, $data['key']);
        return $this->success($info);
    }
}
