<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\AttendanceResource;
use App\Models\OfficeLocation;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\FaceVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AttendanceController extends ApiController
{
    public function __construct(
        private readonly AttendanceService $service,
        private readonly FaceVerificationService $faceVerification,
    ) {}

    // ── Employee ──────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/attendances
     * Employee's attendance history.
     */
    public function index(Request $request): JsonResponse
    {
        $records = $this->service->historyForUser($request->user());

        return $this->paginated($records, AttendanceResource::collection($records));
    }

    /**
     * GET /api/v1/attendances/today
     * Today's attendance record for the authenticated employee.
     */
    public function today(Request $request): JsonResponse
    {
        $attendance = $this->service->todayForUser($request->user());

        return $this->success($attendance ? new AttendanceResource($attendance) : null);
    }

    public function calendar(Request $request): JsonResponse
    {
        $month = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ])['month'];

        $records = $this->service->monthlyHistoryForUser($request->user(), $month);

        return $this->success($records);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $attendance = $this->service->findForUser($request->user(), $id);

        return $this->success(new AttendanceResource($attendance));
    }

    /**
     * POST /api/v1/attendances/clock-in
     * Record attendance in with selfie + GPS.
     */
    /**
     * Foto dikirim tapi tidak sampai ke aplikasi = masalah server, bukan
     * kesalahan karyawan.
     *
     * PHP menampung isi permintaan > 16 KB di berkas sementara (upload_tmp_dir,
     * di produksi tmpfs /tmp 128 MB). Saat /tmp penuh (2 Okt 2026), isi itu
     * hilang dan validasi membalas "Selfie base64 wajib diisi" - karyawan
     * mengulang 30x tanpa hasil, dan tidak ada jejak di log server.
     */
    private function abortIfBodyLost(Request $request): void
    {
        $dikirim = (int) $request->header('Content-Length', '0');
        if ($request->filled('selfie_base64') || $dikirim < 1024) {
            return;
        }

        Log::error('Isi permintaan absen hilang di server (cek ruang upload_tmp_dir / /tmp).', [
            'user_id' => $request->user()?->id,
            'content_length' => $dikirim,
            'upload_tmp_dir' => ini_get('upload_tmp_dir') ?: sys_get_temp_dir(),
            'tmp_free_bytes' => @disk_free_space(ini_get('upload_tmp_dir') ?: sys_get_temp_dir()),
        ]);

        abort(503, 'Foto sudah terkirim, tetapi server sedang gangguan sehingga absen belum tersimpan. Coba lagi beberapa menit lagi; bila masih gagal, laporkan ke HR.');
    }

    public function clockIn(Request $request): JsonResponse
    {
        $user = $request->user();

        $existing = $this->service->todayForUser($user);
        if ($existing) {
            return $this->success(new AttendanceResource($existing), 'Sudah absen masuk hari ini.');
        }

        $this->abortIfBodyLost($request);
        $request->validate([
            'selfie_base64' => ['required', 'string', 'max:2000000'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'address' => ['nullable', 'string', 'max:500'],
            'location_accuracy' => ['nullable', 'numeric', 'min:0'],
            'location_recorded_at' => ['nullable', 'date'],
            'device_id' => ['nullable', 'string', 'max:128'],
        ]);

        // Geofencing check
        $geo = $this->service->checkGeofence(
            $user,
            $request->input('latitude') !== null ? (float) $request->input('latitude') : null,
            $request->input('longitude') !== null ? (float) $request->input('longitude') : null,
        );
        if (! $geo['allowed']) {
            $loc = $geo['location'];
            $dist = $geo['distance'];
            $msg = ($geo['reason'] ?? null) === 'no_coordinates'
                ? 'Lokasi Anda tidak terbaca. Izinkan akses lokasi (GPS) lalu coba lagi.'
                : ($loc
                    ? "Anda berada {$dist} m dari {$loc->name} (radius {$loc->radius_meters} m). Harap absen dari lokasi kantor."
                    : 'Tidak ada lokasi kantor aktif di sekitar Anda.');
            abort(422, $msg);
        }

        $faceCheck = $this->faceVerification->assertAttendanceFaceVerified(
            $user,
            $request->input('selfie_base64')
        );

        $attendance = $this->service->clockIn($user, $request->only(
            'selfie_base64', 'latitude', 'longitude', 'address',
            'location_accuracy', 'location_recorded_at', 'device_id'
        ));
        // Skor kecocokan & wajah hidup untuk audit HR (lihat FaceVerificationService).
        $attendance->forceFill(['face_check_in' => $faceCheck])->save();

        return $this->created(new AttendanceResource($attendance), 'Absen masuk berhasil.');
    }

    /**
     * POST /api/v1/attendances/clock-out
     * Record attendance out with selfie + GPS.
     */
    public function clockOut(Request $request): JsonResponse
    {
        $user = $request->user();
        $attendance = $this->service->todayForUser($user);

        if (! $attendance) {
            abort(422, 'Belum absen masuk hari ini.');
        }
        if ($attendance->time_out) {
            return $this->success(new AttendanceResource($attendance), 'Sudah absen pulang hari ini.');
        }

        $this->abortIfBodyLost($request);
        $request->validate([
            'selfie_base64' => ['required', 'string', 'max:2000000'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'address' => ['nullable', 'string', 'max:500'],
            'location_accuracy' => ['nullable', 'numeric', 'min:0'],
            'location_recorded_at' => ['nullable', 'date'],
            'device_id' => ['nullable', 'string', 'max:128'],
        ]);

        // Geofencing check
        $geo = $this->service->checkGeofence(
            $user,
            $request->input('latitude') !== null ? (float) $request->input('latitude') : null,
            $request->input('longitude') !== null ? (float) $request->input('longitude') : null,
        );
        if (! $geo['allowed']) {
            $loc = $geo['location'];
            $dist = $geo['distance'];
            $msg = ($geo['reason'] ?? null) === 'no_coordinates'
                ? 'Lokasi Anda tidak terbaca. Izinkan akses lokasi (GPS) lalu coba lagi.'
                : ($loc
                    ? "Anda berada {$dist} m dari {$loc->name} (radius {$loc->radius_meters} m). Harap absen dari lokasi kantor."
                    : 'Tidak ada lokasi kantor aktif di sekitar Anda.');
            abort(422, $msg);
        }

        $faceCheck = $this->faceVerification->assertAttendanceFaceVerified(
            $user,
            $request->input('selfie_base64')
        );

        $attendance = $this->service->clockOut($user, $attendance, $request->only(
            'selfie_base64', 'latitude', 'longitude', 'address',
            'location_accuracy', 'location_recorded_at', 'device_id'
        ));
        $attendance->forceFill(['face_check_out' => $faceCheck])->save();

        return $this->success(new AttendanceResource($attendance), 'Absen pulang berhasil.');
    }

    /**
     * GET /api/v1/office-locations
     * Active office locations for the employee's company (for frontend map/geofence preview).
     */
    public function officeLocations(Request $request): JsonResponse
    {
        $locations = OfficeLocation::withoutGlobalScopes()
            ->where('company_id', $request->user()->company_id)
            ->where('is_active', true)
            ->get(['id', 'name', 'address', 'latitude', 'longitude', 'radius_meters']);

        return $this->success($locations);
    }

    // ── Admin ─────────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/admin/attendances
     * Company attendance grid - paginated employees with their records.
     * Query params: date|week|month, search, division, job_title
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'attendance.manage', 'Attendance management access required.');

        $filters = $request->only(['date', 'week', 'month', 'search', 'division', 'job_title']);
        $employees = $this->service->employeesWithAttendance($request->user(), $filters);
        $dates = $this->service->datesForPeriod($filters);

        return $this->paginated($employees, $employees->items(), [
            'dates' => array_map(fn ($d) => $d->toDateString(), $dates),
            'references' => $this->service->adminReferences($request->user()),
        ]);
    }

    public function adminExport(Request $request)
    {
        $this->requireCapability($request->user(), 'attendance.manage', 'Attendance management access required.');

        $payload = $request->validate([
            'date' => ['nullable', 'date'],
            'month' => ['nullable', 'date_format:Y-m'],
            'search' => ['nullable', 'string'],
            'division' => ['nullable'],
            'job_title' => ['nullable'],
        ]);

        return $this->service->exportAdminRecap($request->user(), $payload);
    }

    /**
     * GET /api/v1/admin/attendances/{id}
     * Single attendance record with full detail.
     */
    public function adminShow(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request->user(), 'attendance.manage', 'Attendance management access required.');
        $attendance = $this->service->findWithDetail($id);

        return $this->success(new AttendanceResource($attendance->load('user.division', 'shift')));
    }

    /**
     * POST /api/v1/admin/attendances/manual
     *
     * HR/admin buat absensi manual untuk karyawan - bypass face verification
     * & geofence check. Digunakan sebagai fallback: karyawan lupa absen,
     * kamera error, face descriptor bermasalah, backfill hari-hari lalu.
     *
     * WAJIB isi reason di notes utk audit - accountability & payroll dispute.
     */
    public function adminManualEntry(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'attendance.manage', 'Attendance management access required.');

        $data = $request->validate([
            'user_id' => ['required', 'string', 'exists:users,id'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'time_in' => ['required', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i', 'after:time_in'],
            'status' => ['required', 'in:present,late,off_shift'],
            'notes' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $attendance = $this->service->createManualEntry($admin, $data);

        return $this->created(
            new AttendanceResource($attendance->load('user.division', 'shift')),
            'Absensi manual berhasil disimpan.'
        );
    }

    /**
     * PATCH /api/v1/admin/attendances/{id}/manual
     *
     * HR/admin edit time_in/time_out/status/notes utk correction. Audit log
     * catat before/after state utk trail.
     */
    public function adminManualEdit(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();
        $this->requireCapability($admin, 'attendance.manage', 'Attendance management access required.');

        $data = $request->validate([
            'time_in' => ['nullable', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i', 'after:time_in'],
            'status' => ['nullable', 'in:present,late,off_shift,absent'],
            'notes' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $attendance = $this->service->updateManualEntry($admin, $id, $data);

        return $this->success(
            new AttendanceResource($attendance->load('user.division', 'shift')),
            'Absensi berhasil diperbarui.'
        );
    }

    /**
     * GET /api/v1/admin/users/{user}/attendances
     * Flat history of attendance records for a single user (admin view).
     */
    public function adminUserHistory(Request $request, string $user): JsonResponse
    {
        $this->requireCapability($request->user(), 'attendance.manage', 'Attendance management access required.');

        $target = User::query()->withoutGlobalScopes()
            ->where('company_id', $request->user()->company_id)
            ->where('id', $user)
            ->firstOrFail();

        $perPage = (int) $request->integer('per_page', 30);
        $records = $this->service->historyForUser($target, $perPage);

        return $this->paginated($records, AttendanceResource::collection($records));
    }

    /**
     * GET /api/v1/admin/attendances/summary-report?year=2026&month=5
     *
     * Per-employee monthly attendance KPI: present/late/absent counts,
     * on-time %, avg late minutes, total overtime hours.
     */
    public function summaryReport(Request $request): JsonResponse
    {
        $this->requireCapability($request->user(), 'attendance.manage', 'Attendance management access required.');

        $year = (int) ($request->integer('year') ?: date('Y'));
        $month = (int) ($request->integer('month') ?: date('n'));
        $companyId = (int) $request->user()->company_id;

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));

        $includeInactive = $request->boolean('include_inactive');
        $employees = User::query()
            ->where('company_id', $companyId)
            ->where('group', 'user')
            ->when(! $includeInactive, fn ($q) => $q->whereNotIn('employment_status', User::INACTIVE_STATUSES))
            ->select(['id', 'name', 'email', 'division_id', 'employment_status'])
            ->orderBy('name')
            ->get();

        // Aggregate attendance per user
        $attendance = \DB::table('attendances')
            ->whereIn('user_id', $employees->pluck('id'))
            ->whereBetween('date', [$start, $end])
            ->select(
                'user_id',
                \DB::raw('COUNT(*) as total_days'),
                \DB::raw('SUM(CASE WHEN status="present" THEN 1 ELSE 0 END) as present_count'),
                \DB::raw('SUM(CASE WHEN status="late" THEN 1 ELSE 0 END) as late_count'),
                \DB::raw('SUM(CASE WHEN status="absent" THEN 1 ELSE 0 END) as absent_count'),
                \DB::raw('SUM(CASE WHEN status="off_shift" THEN 1 ELSE 0 END) as off_count'),
                \DB::raw('SUM(CASE WHEN time_out IS NOT NULL THEN 1 ELSE 0 END) as completed_count'),
            )
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        // Aggregate approved overtime hours
        $overtime = \DB::table('overtimes')
            ->whereIn('user_id', $employees->pluck('id'))
            ->whereBetween('date', [$start, $end])
            ->where('status', 'approved')
            ->select('user_id', \DB::raw('SUM(total_hours) as total_hours'))
            ->groupBy('user_id')
            ->pluck('total_hours', 'user_id');

        // Aggregate approved leave days
        $leaves = \DB::table('leaves')
            ->whereIn('user_id', $employees->pluck('id'))
            ->where('status', 'approved')
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('start_date', [$start, $end])
                    ->orWhereBetween('end_date', [$start, $end]);
            })
            ->select('user_id', \DB::raw('SUM(days_count) as total_days'))
            ->groupBy('user_id')
            ->pluck('total_days', 'user_id');

        $rows = $employees->map(function ($e) use ($attendance, $overtime, $leaves) {
            $a = $attendance->get($e->id);
            $total = $a->total_days ?? 0;
            $present = (int) ($a->present_count ?? 0);
            $late = (int) ($a->late_count ?? 0);
            $absent = (int) ($a->absent_count ?? 0);
            $offShift = (int) ($a->off_count ?? 0);
            $completed = (int) ($a->completed_count ?? 0);
            $worked = $present + $late;

            return [
                'id' => $e->id,
                'name' => $e->name,
                'email' => $e->email,
                'division_id' => $e->division_id,
                'total_days' => (int) $total,
                'present_count' => $present,
                'late_count' => $late,
                'absent_count' => $absent,
                'off_shift_count' => $offShift,
                'incomplete_count' => $total - $completed,
                'overtime_hours' => (float) ($overtime[$e->id] ?? 0),
                'leave_days' => (float) ($leaves[$e->id] ?? 0),
                'on_time_pct' => $worked > 0 ? round(($present / $worked) * 100, 1) : 0,
                'attendance_rate' => $total > 0 ? round((($worked) / $total) * 100, 1) : 0,
            ];
        })->values()->all();

        // Company-wide rollup
        $companyTotal = [
            'employees' => count($rows),
            'total_days' => array_sum(array_column($rows, 'total_days')),
            'present' => array_sum(array_column($rows, 'present_count')),
            'late' => array_sum(array_column($rows, 'late_count')),
            'absent' => array_sum(array_column($rows, 'absent_count')),
            'overtime_hours' => array_sum(array_column($rows, 'overtime_hours')),
            'leave_days' => array_sum(array_column($rows, 'leave_days')),
        ];
        $companyTotal['avg_on_time_pct'] = count($rows) > 0
            ? round(array_sum(array_column($rows, 'on_time_pct')) / count($rows), 1)
            : 0;
        $companyTotal['avg_attendance_rate'] = count($rows) > 0
            ? round(array_sum(array_column($rows, 'attendance_rate')) / count($rows), 1)
            : 0;

        return $this->success([
            'year' => $year,
            'month' => $month,
            'period' => ['from' => $start, 'to' => $end],
            'data' => $rows,
            'company' => $companyTotal,
        ]);
    }
}
