<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\JobTitleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobTitleController extends ApiController
{
    public function __construct(private readonly JobTitleService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'master_data.manage', 'Master data access required.');

        $jobTitles = $this->service->paginateForAdmin($user, $request->only(['search', 'division_id', 'page', 'per_page']));

        return $this->paginated($jobTitles, $jobTitles->items(), [
            'divisions' => $this->service->divisionsForAdmin($user),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'master_data.manage', 'Master data access required.');

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'division_id' => ['required', 'integer'],
        ]);

        $jobTitle = $this->service->create($user, $request->only(['name', 'division_id']));

        return $this->created($jobTitle, 'Job title berhasil dibuat.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'master_data.manage', 'Master data access required.');

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'division_id' => ['required', 'integer'],
        ]);

        $jobTitle = $this->service->update($user, $id, $request->only(['name', 'division_id']));

        return $this->success($jobTitle, 'Job title berhasil diperbarui.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'master_data.manage', 'Master data access required.');

        $this->service->delete($user, $id);

        return $this->success(null, 'Job title berhasil dihapus.');
    }
}
