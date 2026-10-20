<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\OfficeLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfficeLocationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'master_data.manage', 'Master data access required.');
        return $this->success(OfficeLocation::query()->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'master_data.manage', 'Master data access required.');

        $data = $request->validate([
            'name'      => ['required', 'string', 'max:100'],
            'address'   => ['nullable', 'string', 'max:500'],
            'latitude'  => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius'    => ['nullable', 'integer', 'min:0', 'max:5000'],
        ]);

        return $this->created(OfficeLocation::create($data), 'Lokasi kantor ditambahkan.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'master_data.manage', 'Master data access required.');

        $location = OfficeLocation::findOrFail($id);
        $data = $request->validate([
            'name'      => ['sometimes', 'string', 'max:100'],
            'address'   => ['sometimes', 'nullable', 'string', 'max:500'],
            'latitude'  => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'radius'    => ['sometimes', 'nullable', 'integer', 'min:0', 'max:5000'],
        ]);

        $location->update($data);
        return $this->success($location->fresh(), 'Lokasi diperbarui.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'master_data.manage', 'Master data access required.');
        OfficeLocation::findOrFail($id)->delete();
        return $this->success(message: 'Lokasi dihapus.');
    }
}
