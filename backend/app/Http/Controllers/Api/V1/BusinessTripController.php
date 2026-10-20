<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BusinessTrip;
use App\Services\AuditLogService;
use App\Services\BusinessTripService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Business Trip (Perjalanan Dinas). Karyawan submit check-in/out di lokasi
 * tugas; HR/admin approve via panel ini. Workflow: pending → approved | rejected.
 */
class BusinessTripController extends ApiController
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly BusinessTripService $service,
    ) {}

    /** GET /api/v1/business-trips - list trips milik user yang login */
    public function myIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = min(50, max(5, (int) $request->integer('per_page', 10)));
        $paginator = $this->service->listForUser($user, $perPage);
        $rows = collect($paginator->items())->map(fn (BusinessTrip $t) => [
            'id' => $t->id,
            'date' => $t->date?->toDateString(),
            'shift_id' => $t->shift_id,
            'destination' => $t->destination,
            'purpose' => $t->purpose,
            'status' => $t->status,
            'notes' => $t->notes,
            'created_at' => $t->created_at?->toIso8601String(),
        ])->values();

        return $this->paginated($paginator, $rows);
    }

    /** GET /api/v1/business-trips/shifts - referensi shift untuk dropdown form */
    public function myShifts(Request $request): JsonResponse
    {
        $shifts = $this->service->shiftsForUser($request->user())
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'start_time' => $s->start_time, 'end_time' => $s->end_time])
            ->values();

        return $this->success($shifts);
    }

    /** POST /api/v1/business-trips - submit pengajuan perjalanan dinas */
    public function myStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'destination' => ['required', 'string', 'min:3', 'max:255'],
            'purpose' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $trip = $this->service->submit($request->user(), $data);

        return $this->created([
            'id' => $trip->id,
            'date' => $trip->date?->toDateString(),
            'destination' => $trip->destination,
            'status' => $trip->status,
        ], 'Pengajuan perjalanan dinas terkirim.');
    }

    /** DELETE /api/v1/business-trips/{id} - cancel pengajuan yang masih pending */
    public function myDestroy(Request $request, int $id): JsonResponse
    {
        $this->service->cancel($request->user(), $id);

        return $this->success(['id' => $id], 'Pengajuan dibatalkan.');
    }

    /** GET /api/v1/admin/business-trips */
    public function adminIndex(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'attendance.manage', 'Attendance access required.');

        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(100, max(10, (int) $request->integer('per_page', 20)));
        $status = $request->string('status')->toString();
        $month = $request->string('month')->toString(); // YYYY-MM
        $q = $request->string('q')->toString();

        $paginator = BusinessTrip::query()
            ->where('company_id', $request->user()->company_id)
            ->with(['user:id,name,email,nip', 'approvedBy:id,name'])
            ->when($status, fn ($w) => $w->where('status', $status))
            ->when($month, fn ($w) => $w->whereYear('date', substr($month, 0, 4))->whereMonth('date', substr($month, 5, 2)))
            ->when($q, fn ($w) => $w->where(function ($x) use ($q) {
                $x->where('destination', 'like', "%{$q}%")
                    ->orWhere('purpose', 'like', "%{$q}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$q}%"));
            }))
            ->orderByDesc('date')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $rows = collect($paginator->items())->map(fn (BusinessTrip $t) => [
            'id' => $t->id,
            'date' => $t->date?->toDateString(),
            'user' => $t->user ? ['id' => $t->user->id, 'name' => $t->user->name, 'nip' => $t->user->nip] : null,
            'destination' => $t->destination,
            'purpose' => $t->purpose,
            'check_in_time' => $t->check_in_time,
            'check_out_time' => $t->check_out_time,
            'late_minutes' => $t->late_minutes,
            'early_out_minutes' => $t->early_out_minutes,
            'check_in_photo' => null,
            'check_out_photo' => null,
            'check_in_photo_available' => false,
            'check_out_photo_available' => false,
            'photo_upload_enabled' => (bool) config('sensitive_file_features.business_trip_photo', false),
            'check_in_lat' => $t->check_in_latitude,
            'check_in_lng' => $t->check_in_longitude,
            'status' => $t->status,
            'notes' => $t->notes,
            'approved_by' => $t->approvedBy ? ['id' => $t->approvedBy->id, 'name' => $t->approvedBy->name] : null,
            'approved_at' => $t->approved_at?->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
        ])->values();

        $pendingCount = BusinessTrip::query()
            ->where('company_id', $request->user()->company_id)
            ->where('status', 'pending')
            ->count();

        return $this->paginated($paginator, $rows, ['pending_count' => $pendingCount]);
    }

    /** POST /api/v1/admin/business-trips/{id}/approve */
    public function approve(Request $request, string $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'attendance.manage', 'Attendance access required.');

        $trip = BusinessTrip::query()
            ->where('company_id', $admin->company_id)
            ->findOrFail($id);

        if ($trip->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'Perjalanan dinas hanya dapat diproses saat berstatus pending.',
            ]);
        }

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);

        $trip->update([
            'status' => 'approved',
            'approved_by' => $admin->id,
            'approved_at' => now(),
            'notes' => $data['notes'] ?? $trip->notes,
        ]);

        $this->audit->log(
            'business_trip.approved',
            $admin, 'business_trip', (string) $trip->id,
            ['user_id' => $trip->user_id, 'date' => $trip->date?->toDateString()],
            $request,
        );

        return $this->success(['id' => $trip->id], 'Perjalanan dinas disetujui.');
    }

    /** POST /api/v1/admin/business-trips/{id}/reject */
    public function reject(Request $request, string $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'attendance.manage', 'Attendance access required.');

        $trip = BusinessTrip::query()
            ->where('company_id', $admin->company_id)
            ->findOrFail($id);

        if ($trip->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'Perjalanan dinas hanya dapat diproses saat berstatus pending.',
            ]);
        }

        $data = $request->validate(['notes' => ['required', 'string', 'max:1000']]);

        $trip->update([
            'status' => 'rejected',
            'approved_by' => $admin->id,
            'approved_at' => now(),
            'notes' => $data['notes'],
        ]);

        $this->audit->log(
            'business_trip.rejected',
            $admin, 'business_trip', (string) $trip->id,
            ['user_id' => $trip->user_id, 'notes' => $data['notes']],
            $request,
        );

        return $this->success(['id' => $trip->id], 'Perjalanan dinas ditolak.');
    }
}
