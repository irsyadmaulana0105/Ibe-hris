<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\DivisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DivisionController extends ApiController
{
    public function __construct(private readonly DivisionService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'master_data.manage', 'Master data access required.');

        $divisions = $this->service->paginateForAdmin($user, $request->only(['search', 'page', 'per_page']));

        // Preload shifts sekali biar tidak N+1
        $shiftIds = collect($divisions->items())->pluck('default_shift_id')->filter()->unique()->values()->all();
        $shiftMap = $shiftIds
            ? \App\Models\Shift::whereIn('id', $shiftIds)->get()->keyBy('id')
            : collect();

        // Counts karyawan per divisi (1 query)
        $divIds = collect($divisions->items())->pluck('id')->all();
        $counts = $divIds
            ? \App\Models\User::withoutGlobalScopes()
                ->where('company_id', $user->company_id)
                ->whereIn('division_id', $divIds)
                ->selectRaw('division_id, COUNT(*) as cnt')
                ->groupBy('division_id')
                ->pluck('cnt', 'division_id')
            : collect();

        $rows = collect($divisions->items())->map(function ($d) use ($shiftMap, $counts) {
            $shift = $d->default_shift_id ? ($shiftMap[$d->default_shift_id] ?? null) : null;
            return [
                'id'   => $d->id,
                'name' => $d->name,
                'default_shift_id' => $d->default_shift_id,
                'default_shift'    => $shift ? [
                    'id'         => $shift->id,
                    'name'       => $shift->name,
                    'start_time' => $shift->start_time,
                    'end_time'   => $shift->end_time,
                ] : null,
                'employee_count'   => (int) ($counts[$d->id] ?? 0),
            ];
        })->values();

        return $this->paginated($divisions, $rows);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'master_data.manage', 'Master data access required.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'default_shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
        ]);

        $division = $this->service->create($user, $data);

        return $this->created($division, 'Division berhasil dibuat.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'master_data.manage', 'Master data access required.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'default_shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
        ]);

        $division = $this->service->update($user, $id, $data);

        return $this->success($division, 'Division berhasil diperbarui.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->requireCapability($user, 'master_data.manage', 'Master data access required.');

        $this->service->delete($user, $id);

        return $this->success(null, 'Division berhasil dihapus.');
    }
}
