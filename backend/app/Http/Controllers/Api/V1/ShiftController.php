<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftController extends ApiController
{
    public function __construct(private readonly ShiftService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'master_data.manage', 'Master data access required.');

        $shifts = $this->service->paginateForAdmin($user, $request->only(['search', 'page', 'per_page']));

        return $this->paginated($shifts, $shifts->items());
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'master_data.manage', 'Master data access required.');

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'grace_period_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
        ]);

        $shift = $this->service->create($user, $request->only(['name', 'start_time', 'end_time', 'grace_period_minutes']));

        return $this->created($shift, 'Shift berhasil dibuat.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'master_data.manage', 'Master data access required.');

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'grace_period_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
        ]);

        $shift = $this->service->update($user, $id, $request->only(['name', 'start_time', 'end_time', 'grace_period_minutes']));

        return $this->success($shift, 'Shift berhasil diperbarui.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'master_data.manage', 'Master data access required.');

        $this->service->delete($user, $id);

        return $this->success(null, 'Shift berhasil dihapus.');
    }
}
