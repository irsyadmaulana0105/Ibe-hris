<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\OvertimeResource;
use App\Services\OvertimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OvertimeController extends ApiController
{
    public function __construct(private readonly OvertimeService $service) {}

    // ── Employee ──────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/overtimes
     */
    public function index(Request $request): JsonResponse
    {
        $overtimes = $this->service->listForUser($request->user());

        return $this->paginated($overtimes, OvertimeResource::collection($overtimes));
    }

    /**
     * POST /api/v1/overtimes
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'attachment' => ['prohibited'],
        ]);

        $overtime = $this->service->submit($request->user(), $data, $request->file('attachment'));

        return $this->created(new OvertimeResource($overtime), __('Overtime request submitted.'));
    }

    /**
     * DELETE /api/v1/overtimes/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->service->cancel($request->user(), $id);

        return $this->success(message: __('Overtime request cancelled.'));
    }

    // ── Admin ─────────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/admin/overtimes
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'overtime.manage', 'Overtime management access required.');

        $overtimes = $this->service->listForAdmin(
            $request->user(),
            $request->only(['status', 'month', 'division', 'search'])
        );

        return $this->paginated($overtimes, OvertimeResource::collection($overtimes), [
            'pending_count' => $this->service->pendingCount($request->user()->company_id),
            'summary' => $this->service->summaryForAdmin(
                $request->user(),
                $request->only(['month', 'division', 'search'])
            ),
        ]);
    }

    /**
     * GET /api/v1/admin/overtimes/{id}
     */
    public function adminShow(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'overtime.manage', 'Overtime management access required.');

        return $this->success(new OvertimeResource($this->service->detail($request->user(), $id)));
    }

    /**
     * POST /api/v1/admin/overtimes/{id}/approve
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'overtime.manage', 'Overtime management access required.');
        $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        $overtime = $this->service->approve($request->user(), $id, $request->notes);

        return $this->success(new OvertimeResource($overtime), __('Overtime approved.'));
    }

    /**
     * POST /api/v1/admin/overtimes/{id}/reject
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'overtime.manage', 'Overtime management access required.');
        $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        $overtime = $this->service->reject($request->user(), $id, $request->notes);

        return $this->success(new OvertimeResource($overtime), __('Overtime rejected.'));
    }
}
