<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\EducationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EducationController extends ApiController
{
    public function __construct(private readonly EducationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'master_data.manage', 'Master data access required.');

        $educations = $this->service->paginateForAdmin($user, $request->only(['search', 'page', 'per_page']));

        return $this->paginated($educations, $educations->items());
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'master_data.manage', 'Master data access required.');

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $education = $this->service->create($user, $request->only('name'));

        return $this->created($education, 'Education berhasil dibuat.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'master_data.manage', 'Master data access required.');

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $education = $this->service->update($user, $id, $request->only('name'));

        return $this->success($education, 'Education berhasil diperbarui.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'master_data.manage', 'Master data access required.');

        $this->service->delete($user, $id);

        return $this->success(null, 'Education berhasil dihapus.');
    }
}
