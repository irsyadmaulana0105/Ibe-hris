<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\AutoShiftResource;
use App\Http\Resources\ShiftMappingResource;
use App\Services\ShiftScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftScheduleController extends ApiController
{
    public function __construct(private readonly ShiftScheduleService $service) {}

    public function references(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'attendance.manage', 'Attendance management access required.');

        return $this->success($this->service->references($user));
    }

    public function autoShifts(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'attendance.manage', 'Attendance management access required.');

        $rows = $this->service->autoShifts($user, $request->only(['job_title_id', 'page', 'per_page']));

        return $this->paginated($rows, AutoShiftResource::collection($rows));
    }

    public function saveAutoShift(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'attendance.manage', 'Attendance management access required.');

        $payload = $request->validate([
            'job_title_id' => ['required', 'integer'],
            'shift_id' => ['required', 'integer'],
        ]);

        $autoShift = $this->service->saveAutoShift($user, $payload);

        return $this->success(new AutoShiftResource($autoShift), 'Default shift berhasil disimpan.');
    }

    public function deleteAutoShift(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'attendance.manage', 'Attendance management access required.');

        $this->service->deleteAutoShift($user, $id);

        return $this->success(null, 'Default shift berhasil dihapus.');
    }

    public function mappings(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'attendance.manage', 'Attendance management access required.');

        $rows = $this->service->mappings($user, $request->only(['date', 'month', 'user_id', 'page', 'per_page']));

        return $this->paginated($rows, ShiftMappingResource::collection($rows));
    }

    public function saveMapping(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'attendance.manage', 'Attendance management access required.');

        $payload = $request->validate([
            'user_id' => ['required', 'string'],
            'shift_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
        ]);

        $mapping = $this->service->saveMapping($user, $payload);

        return $this->success(new ShiftMappingResource($mapping), 'Mapping shift berhasil disimpan.');
    }

    public function deleteMapping(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'attendance.manage', 'Attendance management access required.');

        $this->service->deleteMapping($user, $id);

        return $this->success(null, 'Mapping shift berhasil dihapus.');
    }
}
