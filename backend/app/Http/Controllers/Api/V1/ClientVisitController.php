<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ClientVisit;
use App\Services\AuditLogService;
use App\Services\ClientVisitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Kunjungan Klien (Client Visit). Karyawan log check-in/out di lokasi klien
 * (sales/service visit). Admin view-only; visit tidak memerlukan approval manual.
 */
class ClientVisitController extends ApiController
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly ClientVisitService $service,
    ) {}

    /** GET /api/v1/client-visits - list kunjungan milik user yang login */
    public function myIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = min(50, max(5, (int) $request->integer('per_page', 10)));
        $paginator = $this->service->listForUser($user, $perPage);
        $rows = collect($paginator->items())->map(fn (ClientVisit $v) => [
            'id' => $v->id,
            'date' => $v->date?->toDateString(),
            'visit_in' => $v->visit_in?->toIso8601String(),
            'visit_out' => $v->visit_out?->toIso8601String(),
            'notes_in' => $v->notes_in,
            'notes_out' => $v->notes_out,
            'photo_in' => null,
            'photo_out' => null,
            'photo_in_available' => false,
            'photo_out_available' => false,
            'photo_upload_enabled' => (bool) config('sensitive_file_features.client_visit_photo', false),
            'status' => $v->status,
            'duration_minutes' => ($v->visit_in && $v->visit_out)
                ? abs($v->visit_out->diffInMinutes($v->visit_in))
                : null,
        ])->values();

        return $this->paginated($paginator, $rows);
    }

    /** POST /api/v1/client-visits/in - karyawan check-in kunjungan klien */
    public function myVisitIn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'notes_in' => ['required', 'string', 'min:3', 'max:500'],
            'photo_in' => ['prohibited'],
        ]);
        $visit = $this->service->visitIn($request->user(), [
            'date' => $data['date'],
            'notes_in' => $data['notes_in'],
        ], $request->file('photo_in'));

        return $this->created(['id' => $visit->id, 'status' => $visit->status], 'Kunjungan dimulai.');
    }

    /** POST /api/v1/client-visits/{id}/out - karyawan check-out kunjungan */
    public function myVisitOut(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'notes_out' => ['required', 'string', 'min:3', 'max:500'],
            'photo_out' => ['prohibited'],
        ]);
        $visit = $this->service->visitOut($request->user(), $id, [
            'notes_out' => $data['notes_out'],
        ], $request->file('photo_out'));

        return $this->success(['id' => $visit->id, 'status' => $visit->status], 'Kunjungan selesai.');
    }

    /** GET /api/v1/admin/client-visits */
    public function adminIndex(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'attendance.manage', 'Attendance access required.');

        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(100, max(10, (int) $request->integer('per_page', 20)));
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['ongoing', 'completed'])],
        ]);
        $status = (string) ($filters['status'] ?? '');
        $month = $request->string('month')->toString();
        $q = $request->string('q')->toString();

        $paginator = ClientVisit::query()
            ->where('company_id', $request->user()->company_id)
            ->with('user:id,name,email,nip,division_id')
            ->when($status, fn ($w) => $w->where('status', $status))
            ->when($month, fn ($w) => $w->whereYear('date', substr($month, 0, 4))->whereMonth('date', substr($month, 5, 2)))
            ->when($q, fn ($w) => $w->where(function ($x) use ($q) {
                $x->where('notes_in', 'like', "%{$q}%")
                    ->orWhere('notes_out', 'like', "%{$q}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$q}%"));
            }))
            ->orderByDesc('date')
            ->orderByDesc('visit_in')
            ->paginate($perPage, ['*'], 'page', $page);

        $rows = collect($paginator->items())->map(fn (ClientVisit $v) => [
            'id' => $v->id,
            'date' => $v->date?->toDateString(),
            'user' => $v->user ? ['id' => $v->user->id, 'name' => $v->user->name, 'nip' => $v->user->nip] : null,
            'visit_in' => $v->visit_in?->toIso8601String(),
            'visit_out' => $v->visit_out?->toIso8601String(),
            'notes_in' => $v->notes_in,
            'notes_out' => $v->notes_out,
            'photo_in' => null,
            'photo_out' => null,
            'photo_in_available' => false,
            'photo_out_available' => false,
            'photo_upload_enabled' => (bool) config('sensitive_file_features.client_visit_photo', false),
            'lat_in' => $v->latitude_in,
            'lng_in' => $v->longitude_in,
            'lat_out' => $v->latitude_out,
            'lng_out' => $v->longitude_out,
            'status' => $v->status,
            'duration_minutes' => ($v->visit_in && $v->visit_out)
                ? abs($v->visit_out->diffInMinutes($v->visit_in))
                : null,
        ])->values();

        return $this->paginated($paginator, $rows);
    }

    /** GET /api/v1/admin/client-visits/{id} */
    public function adminShow(Request $request, string $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'attendance.manage', 'Attendance access required.');

        $v = ClientVisit::query()
            ->where('company_id', $request->user()->company_id)
            ->with('user:id,name,email,nip')
            ->findOrFail($id);

        return $this->success([
            'id' => $v->id,
            'date' => $v->date?->toDateString(),
            'user' => $v->user ? ['id' => $v->user->id, 'name' => $v->user->name, 'nip' => $v->user->nip] : null,
            'visit_in' => $v->visit_in?->toIso8601String(),
            'visit_out' => $v->visit_out?->toIso8601String(),
            'notes_in' => $v->notes_in,
            'notes_out' => $v->notes_out,
            'photo_in' => null,
            'photo_out' => null,
            'photo_in_available' => false,
            'photo_out_available' => false,
            'photo_upload_enabled' => (bool) config('sensitive_file_features.client_visit_photo', false),
            'lat_in' => $v->latitude_in,
            'lng_in' => $v->longitude_in,
            'lat_out' => $v->latitude_out,
            'lng_out' => $v->longitude_out,
            'status' => $v->status,
        ]);
    }
}
