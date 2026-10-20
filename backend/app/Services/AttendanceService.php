<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AutoShift;
use App\Models\Division;
use App\Models\JobTitle;
use App\Models\OfficeLocation;
use App\Models\Shift;
use App\Models\ShiftMapping;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceService
{
    public function __construct(
        private readonly PayrollService $payrolls,
        private readonly PrivateMediaStorage $privateMedia,
    ) {}

    // ── Admin queries ─────────────────────────────────────────────────────────

    /**
     * Paginated employee list with their attendance records for a given period.
     * Replicates the caching logic from Admin/AttendanceComponent.
     */
    public function employeesWithAttendance(
        User $admin,
        array $filters = [],
        int $perPage = 20
    ): \Illuminate\Pagination\LengthAwarePaginator {
        return $this->buildAdminEmployeeQuery($admin, $filters)
            ->paginate($perPage)
            ->through(
                fn (User $user) => $this->attachAttendancesToUser($user, $filters)
            );
    }

    /**
     * List of dates for the selected period (for the attendance grid header).
     */
    public function datesForPeriod(array $filters): array
    {
        if (! empty($filters['date'])) {
            return [Carbon::parse($filters['date'])];
        }
        if (! empty($filters['week'])) {
            $start = Carbon::parse($filters['week'])->startOfWeek();

            return $start->range($start->copy()->endOfWeek())->toArray();
        }
        if (! empty($filters['month'])) {
            $start = Carbon::parse($filters['month'])->startOfMonth();

            return $start->range($start->copy()->endOfMonth())->toArray();
        }

        return [today()];
    }

    public function adminReferences(User $admin): array
    {
        return [
            'divisions' => Division::query()
                ->where('company_id', $admin->company_id)
                ->orderBy('name')
                ->get(['id', 'name']),
            'job_titles' => JobTitle::query()
                ->where('company_id', $admin->company_id)
                ->orderBy('name')
                ->get(['id', 'name', 'division_id']),
        ];
    }

    public function exportAdminRecap(User $admin, array $filters = []): StreamedResponse
    {
        $dates = collect($this->datesForPeriod($filters))->map(fn (Carbon $date) => $date->toDateString())->values();
        $employees = $this->buildAdminEmployeeQuery($admin, $filters)
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->attachAttendancesToUser($user, $filters));

        $summary = [
            'employees' => $employees->count(),
            'present' => 0,
            'late' => 0,
            'absent' => 0,
            'sick' => 0,
            'excused' => 0,
            'empty' => 0,
        ];

        foreach ($employees as $employee) {
            foreach ($dates as $date) {
                $record = collect($employee->attendances ?? [])->firstWhere('date', $date);
                $status = $record['status'] ?? null;
                if ($status && array_key_exists($status, $summary)) {
                    $summary[$status]++;
                } else {
                    $summary['empty']++;
                }
            }
        }

        $periodLabel = ! empty($filters['month'])
            ? Carbon::parse($filters['month'].'-01')->translatedFormat('F Y')
            : (! empty($filters['date']) ? Carbon::parse($filters['date'])->toDateString() : today()->toDateString());
        $fileSuffix = ! empty($filters['month']) ? $filters['month'] : (! empty($filters['date']) ? $filters['date'] : today()->toDateString());

        return response()->streamDownload(function () use ($employees, $dates, $summary, $periodLabel): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, ['Attendance Recap Report']);
            fputcsv($handle, ['Period', $periodLabel]);
            fputcsv($handle, ['Total Employees', $summary['employees']]);
            fputcsv($handle, ['Present', $summary['present']]);
            fputcsv($handle, ['Late', $summary['late']]);
            fputcsv($handle, ['Absent', $summary['absent']]);
            fputcsv($handle, ['Sick', $summary['sick']]);
            fputcsv($handle, ['Excused', $summary['excused']]);
            fputcsv($handle, ['Empty', $summary['empty']]);
            fputcsv($handle, []);

            $headers = ['Employee Name', 'NIP', 'Email', 'Division', 'Job Title', 'Present', 'Late', 'Absent', 'Sick', 'Excused'];
            foreach ($dates as $date) {
                $headers[] = $date;
            }
            fputcsv($handle, $headers);

            foreach ($employees as $employee) {
                $records = collect($employee->attendances ?? []);
                $counts = [
                    'present' => 0,
                    'late' => 0,
                    'absent' => 0,
                    'sick' => 0,
                    'excused' => 0,
                ];

                $row = [
                    $employee->name,
                    $employee->nip,
                    $employee->email,
                    $employee->division?->name,
                    $employee->jobTitle?->name,
                ];

                foreach ($dates as $date) {
                    $status = $records->firstWhere('date', $date)['status'] ?? '';
                    if ($status !== '' && array_key_exists($status, $counts)) {
                        $counts[$status]++;
                    }
                }

                $row[] = $counts['present'];
                $row[] = $counts['late'];
                $row[] = $counts['absent'];
                $row[] = $counts['sick'];
                $row[] = $counts['excused'];

                foreach ($dates as $date) {
                    $record = $records->firstWhere('date', $date);
                    $row[] = $record
                        ? strtoupper((string) $record['status']).' ('.(($record['time_in'] ?? '--:--').'-'.($record['time_out'] ?? '--:--')).')'
                        : '';
                }

                fputcsv($handle, $row);
            }

            fclose($handle);
        }, 'attendance-recap-'.$fileSuffix.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    // ── Mobile clock-in / clock-out ───────────────────────────────────────────

    /**
     * Haversine distance in meters between two GPS coordinates.
     */
    public function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371000; // Earth radius in meters
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
              + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Check if GPS coords are within any active office location.
     * Returns ['allowed' => bool, 'location' => ?OfficeLocation, 'distance' => float].
     */
    public function checkGeofence(User $user, ?float $lat, ?float $lng): array
    {
        if (($user->attendance_location_mode ?: 'office_geofence') === 'mobile_field') {
            return ['allowed' => true, 'location' => null, 'distance' => 0];
        }

        $locations = OfficeLocation::withoutGlobalScopes()
            ->where('company_id', $user->company_id)
            ->where('is_active', true)
            ->get();

        if ($locations->isEmpty() || $lat === null || $lng === null) {
            return ['allowed' => true, 'location' => null, 'distance' => 0];
        }

        $nearest = null;
        $minDist = PHP_FLOAT_MAX;

        foreach ($locations as $loc) {
            $dist = $this->distanceMeters($lat, $lng, $loc->latitude, $loc->longitude);
            if ($dist < $minDist) {
                $minDist = $dist;
                $nearest = $loc;
            }
            if ($dist <= $loc->radius_meters) {
                return ['allowed' => true, 'location' => $loc, 'distance' => round($dist)];
            }
        }

        return ['allowed' => false, 'location' => $nearest, 'distance' => round($minDist)];
    }

    /**
     * Validate the evidence supplied by a mobile-field employee. A browser cannot
     * prove that GPS has not been mocked, so this deliberately combines fresh,
     * high-accuracy coordinates, a stable device identifier and an impossible-
     * travel check. Native device attestation can be added without changing the
     * attendance schema or API contract.
     */
    public function validateLocationEvidence(User $user, array $data): array
    {
        $mode = $user->attendance_location_mode ?: 'office_geofence';
        if ($mode !== 'mobile_field') {
            return ['policy' => $mode, 'risk_flags' => []];
        }

        foreach (['latitude', 'longitude', 'location_accuracy', 'location_recorded_at', 'device_id'] as $field) {
            if (! isset($data[$field]) || $data[$field] === '') {
                abort(422, "Bukti lokasi {$field} wajib untuk absensi Sales mobile.");
            }
        }

        $accuracy = (float) $data['location_accuracy'];
        if ($accuracy <= 0 || $accuracy > 100) {
            abort(422, 'Akurasi lokasi harus 100 meter atau lebih baik. Aktifkan GPS presisi lalu coba lagi.');
        }

        $recordedAt = Carbon::parse($data['location_recorded_at']);
        $ageSeconds = $recordedAt->diffInSeconds(Carbon::now(), false);
        if ($ageSeconds < -30 || $ageSeconds > 120) {
            abort(422, 'Lokasi sudah kedaluwarsa atau waktu perangkat tidak sesuai. Ambil ulang lokasi lalu coba lagi.');
        }

        $last = Attendance::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereNotNull('selfie_in_latitude')
            ->whereNotNull('selfie_in_longitude')
            ->latest('selfie_in_taken_at')
            ->first();

        if ($last?->selfie_in_taken_at) {
            $distance = $this->distanceMeters(
                (float) $last->selfie_in_latitude,
                (float) $last->selfie_in_longitude,
                (float) $data['latitude'],
                (float) $data['longitude'],
            );
            $hours = max(1 / 60, $last->selfie_in_taken_at->diffInSeconds(Carbon::now()) / 3600);
            if ($distance > 10_000 && ($distance / 1000) / $hours > 250) {
                abort(422, 'Perpindahan lokasi tidak wajar terdeteksi. Absensi ditahan untuk verifikasi HR.');
            }
        }

        return ['policy' => $mode, 'risk_flags' => []];
    }

    public function clockIn(User $user, array $data): Attendance
    {
        $now = Carbon::now(config('app.timezone'));
        $today = $now->toDateString();
        $shift = $this->getShiftForUser($user, $today);

        // Status logic:
        //   present   - clock-in dalam jendela [shift_start - 60min, shift_start + grace_period]
        //   late      - clock-in lewat grace_period dari shift_start tapi MASIH di hari/shift yang sama
        //   off_shift - clock-in di luar jendela [shift_start - 60min, shift_end + 60min].
        //               Indikator anomali: karyawan absen di luar jam kerjanya (mungkin
        //               salah shift, lembur tanpa request, atau swap shift tanpa mapping).
        //
        // Grace period per shift configurable via shifts.grace_period_minutes (default 15).
        // Late_minutes dihitung sejak shift_start (BUKAN sejak grace berakhir) agar tier
        // payroll lookup match expectation - "telat 20 menit" = 20 menit dari shift_start.
        $status = 'present';
        $lateMinutes = 0;
        if ($shift) {
            $shiftStart = Carbon::today(config('app.timezone'))->setTimeFromTimeString($shift->start_time);
            $shiftEnd = Carbon::today(config('app.timezone'))->setTimeFromTimeString($shift->end_time ?: $shift->start_time);
            $windowOpen = $shiftStart->copy()->subMinutes(60);
            $windowClose = $shiftEnd->copy()->addMinutes(60);
            // Grace order: shift.grace_period_minutes (per-shift override)
            // → company.late_grace_default_minutes (company default sesuai kontrak)
            // → 15 menit (legacy safety)
            $companyDefault = (int) ($user->company?->late_grace_default_minutes ?? 15);
            $gracePeriod = (int) ($shift->grace_period_minutes ?? $companyDefault);

            if ($now->lt($windowOpen) || $now->gt($windowClose)) {
                $status = 'off_shift';
            } elseif ($now->gt($shiftStart->copy()->addMinutes($gracePeriod))) {
                $status = 'late';
                $lateMinutes = max(0, (int) $shiftStart->diffInMinutes($now, false));
            } else {
                $status = 'present';
            }
        }

        $locationEvidence = $this->validateLocationEvidence($user, $data);
        $stored = null;
        $attendanceId = null;
        try {
            $attendance = DB::transaction(function () use (
                $user,
                $data,
                $today,
                $now,
                $shift,
                $locationEvidence,
                $status,
                $lateMinutes,
                &$stored,
                &$attendanceId,
            ): Attendance {
                $attendance = Attendance::create([
                    'user_id' => $user->id,
                    'date' => $today,
                    'time_in' => $now->format('H:i:s'),
                    'shift_id' => $shift?->id,
                    'latitude' => $data['latitude'] ?? null,
                    'longitude' => $data['longitude'] ?? null,
                    'location_policy' => $locationEvidence['policy'],
                    'status' => $status,
                    'late_minutes' => $lateMinutes,
                    'selfie_in_latitude' => $data['latitude'] ?? null,
                    'selfie_in_longitude' => $data['longitude'] ?? null,
                    'selfie_in_accuracy_m' => $data['location_accuracy'] ?? null,
                    'selfie_in_taken_at' => $now,
                    'selfie_in_location_recorded_at' => $data['location_recorded_at'] ?? null,
                    'selfie_in_location_risk_flags' => $locationEvidence['risk_flags'],
                    'attendance_device_id' => $data['device_id'] ?? null,
                    'selfie_in_address' => $data['address'] ?? null,
                ]);
                $attendanceId = $attendance->id;

                if (! empty($data['selfie_base64'])) {
                    $stored = $this->privateMedia->storeImageDataUri(
                        (int) $user->company_id,
                        AttendanceReimbursementMediaService::ATTENDANCE_CATEGORY,
                        $attendance->id,
                        'selfie-in',
                        $data['selfie_base64'],
                    );
                    $attendance->update($this->mediaAttributes('selfie_in', $stored, 'selfie_in_path'));
                }

                return $attendance->fresh();
            });
        } catch (\Throwable $failure) {
            if ($stored !== null) {
                $this->privateMedia->deletePrivate(
                    (int) $user->company_id,
                    AttendanceReimbursementMediaService::ATTENDANCE_CATEGORY,
                    $attendanceId,
                    'selfie-in',
                    $stored['path'],
                );
            }
            throw $failure;
        }

        Attendance::clearUserAttendanceCache($user, $now);

        return $attendance;
    }

    public function clockOut(User $user, Attendance $attendance, array $data): Attendance
    {
        $now = Carbon::now(config('app.timezone'));

        $locationEvidence = $this->validateLocationEvidence($user, $data);
        $stored = null;
        try {
            if (! empty($data['selfie_base64'])) {
                $stored = $this->privateMedia->storeImageDataUri(
                    (int) $user->company_id,
                    AttendanceReimbursementMediaService::ATTENDANCE_CATEGORY,
                    $attendance->id,
                    'selfie-out',
                    $data['selfie_base64'],
                );
            }
            DB::transaction(function () use ($attendance, $data, $now, $locationEvidence, $stored): void {
                $attendance->update([
                    'time_out' => $now->format('H:i:s'),
                    'selfie_out_latitude' => $data['latitude'] ?? null,
                    'selfie_out_longitude' => $data['longitude'] ?? null,
                    'selfie_out_accuracy_m' => $data['location_accuracy'] ?? null,
                    'selfie_out_taken_at' => $now,
                    'selfie_out_location_recorded_at' => $data['location_recorded_at'] ?? null,
                    'selfie_out_location_risk_flags' => $locationEvidence['risk_flags'],
                    'attendance_device_id' => $data['device_id'] ?? $attendance->attendance_device_id,
                    'selfie_out_address' => $data['address'] ?? null,
                    ...($stored === null ? [] : $this->mediaAttributes('selfie_out', $stored, 'selfie_out_path')),
                ]);
            });
        } catch (\Throwable $failure) {
            if ($stored !== null) {
                $this->privateMedia->deletePrivate(
                    (int) $user->company_id,
                    AttendanceReimbursementMediaService::ATTENDANCE_CATEGORY,
                    $attendance->id,
                    'selfie-out',
                    $stored['path'],
                );
            }
            throw $failure;
        }

        Attendance::clearUserAttendanceCache($user, $now);

        return $attendance->fresh();
    }

    /**
     * HR/admin buat absensi manual - bypass face verification + geofence.
     * Simpan reason di notes untuk audit trail. Log ke AuditLogService.
     */
    public function createManualEntry(User $admin, array $data): Attendance
    {
        $target = User::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->where('id', $data['user_id'])
            ->firstOrFail();

        $shift = $this->getShiftForUser($target, $data['date']);

        // Cek duplikasi - kalau sudah ada attendance di tanggal itu, error
        $existing = Attendance::withoutGlobalScopes()
            ->where('user_id', $target->id)
            ->where('date', $data['date'])
            ->first();
        if ($existing) {
            abort(422, 'Karyawan sudah punya absensi di tanggal tersebut. Gunakan edit untuk mengubah.');
        }

        $lateMinutes = 0;
        if ($shift && $data['status'] === 'late') {
            $shiftStart = Carbon::parse($data['date'])->setTimeFromTimeString($shift->start_time);
            $actualIn = Carbon::parse($data['date'])->setTimeFromTimeString($data['time_in'].':00');
            $lateMinutes = max(0, (int) $shiftStart->diffInMinutes($actualIn, false));
        }

        $attendance = Attendance::create([
            'user_id' => $target->id,
            'date' => $data['date'],
            'time_in' => $data['time_in'].':00',
            'time_out' => ! empty($data['time_out']) ? $data['time_out'].':00' : null,
            'shift_id' => $shift?->id,
            'status' => $data['status'],
            'late_minutes' => $lateMinutes,
            'note' => '[Manual entry by '.$admin->name.'] '.$data['notes'],
        ]);

        app(AuditLogService::class)->log(
            'attendance.manual_entry',
            $admin, 'attendance', (string) $attendance->id,
            [
                'target_user_id' => $target->id,
                'target_user_name' => $target->name,
                'date' => $data['date'],
                'time_in' => $data['time_in'],
                'time_out' => $data['time_out'] ?? null,
                'status' => $data['status'],
                'reason' => $data['notes'],
            ]
        );

        Attendance::clearUserAttendanceCache($target, Carbon::parse($data['date']));

        return $attendance->fresh();
    }

    /**
     * HR/admin edit absensi existing - koreksi typo, backfill time_out, dll.
     * Log audit before/after.
     */
    public function updateManualEntry(User $admin, int $attendanceId, array $data): Attendance
    {
        $attendance = Attendance::withoutGlobalScopes()
            ->whereHas('user', fn ($q) => $q->where('company_id', $admin->company_id))
            ->findOrFail($attendanceId);

        $before = [
            'time_in' => $attendance->time_in,
            'time_out' => $attendance->time_out,
            'status' => $attendance->status,
            'note' => $attendance->note,
        ];

        $update = ['note' => '[Edit by '.$admin->name.'] '.$data['notes']];
        if (! empty($data['time_in'])) {
            $update['time_in'] = $data['time_in'].':00';
        }
        if (! empty($data['time_out'])) {
            $update['time_out'] = $data['time_out'].':00';
        }
        if (! empty($data['status'])) {
            $update['status'] = $data['status'];
        }

        $attendance->update($update);

        app(AuditLogService::class)->log(
            'attendance.manual_edit',
            $admin, 'attendance', (string) $attendance->id,
            [
                'target_user_id' => $attendance->user_id,
                'before' => $before,
                'after' => $update,
                'reason' => $data['notes'],
            ]
        );

        Attendance::clearUserAttendanceCache($attendance->user, Carbon::parse($attendance->date));

        return $attendance->fresh();
    }

    /**
     * Resolve shift karyawan untuk tanggal tertentu. Hirarki:
     *   1. ShiftMapping per-day (paling spesifik)
     *   2. users.default_shift_id (per-user permanent override)
     *   3. AutoShift per job_title (jabatan default)
     *   4. divisions.default_shift_id (divisi default)
     *   5. Company first shift (fallback)
     */
    private function getShiftForUser(User $user, string $date): ?Shift
    {
        // 1. Per-day mapping
        $mapping = ShiftMapping::where('user_id', $user->id)->where('date', $date)->first();
        if ($mapping) {
            return $mapping->shift;
        }

        // 2. Per-user default
        if ($user->default_shift_id) {
            $shift = Shift::find($user->default_shift_id);
            if ($shift) {
                return $shift;
            }
        }

        // 3. Per-job_title default
        if ($user->job_title_id) {
            $autoShift = AutoShift::where('job_title_id', $user->job_title_id)->first();
            if ($autoShift) {
                return $autoShift->shift;
            }
        }

        // 4. Per-division default
        if ($user->division_id) {
            $division = Division::find($user->division_id);
            if ($division && $division->default_shift_id) {
                $shift = Shift::find($division->default_shift_id);
                if ($shift) {
                    return $shift;
                }
            }
        }

        // 5. Company fallback
        return Shift::where('company_id', $user->company_id)->first();
    }

    // ── Employee queries ──────────────────────────────────────────────────────

    public function todayForUser(User $user): ?Attendance
    {
        return Attendance::where('user_id', $user->id)
            ->where('date', today()->toDateString())
            ->first();
    }

    public function historyForUser(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return Attendance::where('user_id', $user->id)
            ->orderByDesc('date')
            ->paginate($perPage);
    }

    public function monthlyHistoryForUser(User $user, string $month): array
    {
        $date = Carbon::parse($month.'-01');
        $start = $date->copy()->startOfMonth();
        $end = $date->copy()->endOfMonth();
        $records = Attendance::where('user_id', $user->id)
            ->whereMonth('date', $date->month)
            ->whereYear('date', $date->year)
            ->with('shift')
            ->orderBy('date')
            ->get()
            ->keyBy(fn (Attendance $attendance) => $attendance->date?->toDateString());
        $holidays = $this->payrolls->getHolidayDates($start, $end);
        $items = [];

        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $dateKey = $cursor->toDateString();
            $attendance = $records->get($dateKey);

            if ($attendance) {
                $items[] = [
                    'id' => $attendance->id,
                    'date' => $dateKey,
                    'status' => $attendance->status,
                    'label' => null,
                    'time_in' => $attendance->time_in?->format('H:i:s'),
                    'time_out' => $attendance->time_out?->format('H:i:s'),
                    'is_recorded' => true,
                ];
            } elseif (isset($holidays[$dateKey])) {
                $items[] = [
                    'id' => null,
                    'date' => $dateKey,
                    'status' => 'holiday',
                    'label' => $holidays[$dateKey],
                    'time_in' => null,
                    'time_out' => null,
                    'is_recorded' => false,
                ];
            } elseif ($cursor->isWeekend()) {
                $items[] = [
                    'id' => null,
                    'date' => $dateKey,
                    'status' => 'weekend',
                    'label' => 'Akhir Pekan',
                    'time_in' => null,
                    'time_out' => null,
                    'is_recorded' => false,
                ];
            } else {
                $items[] = [
                    'id' => null,
                    'date' => $dateKey,
                    'status' => 'no_record',
                    'label' => 'Belum Ada Record',
                    'time_in' => null,
                    'time_out' => null,
                    'is_recorded' => false,
                ];
            }

            $cursor->addDay();
        }

        return $items;
    }

    public function findForUser(User $user, int $attendanceId): Attendance
    {
        return Attendance::where('user_id', $user->id)
            ->with('shift')
            ->findOrFail($attendanceId);
    }

    // ── Shared ────────────────────────────────────────────────────────────────

    public function findWithDetail(int $attendanceId): Attendance
    {
        return Attendance::findOrFail($attendanceId);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function attachAttendancesToUser(User $user, array $filters): User
    {
        $attendances = $this->fetchCachedAttendances($user, $filters);
        $user->attendances = new Collection($attendances ?? []);

        return $user;
    }

    private function buildAdminEmployeeQuery(User $admin, array $filters)
    {
        $query = User::where('group', 'user')
            ->with(['division:id,name', 'jobTitle:id,name,division_id'])
            ->where('company_id', $admin->company_id);

        // Default sembunyikan karyawan resigned/terminated dari halaman
        // attendance - yang sudah off-boarded tidak perlu muncul lagi di
        // list rekap kehadiran. Admin bisa explicit set `include_inactive=1`
        // untuk audit historis (mis. cek absensi karyawan saat masih aktif).
        if (empty($filters['include_inactive'])) {
            $query->active();
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('nip', 'like', "%{$search}%")
            );
        }
        if (! empty($filters['division'])) {
            $query->where('division_id', $filters['division']);
        }
        if (! empty($filters['job_title'])) {
            $query->where('job_title_id', $filters['job_title']);
        }

        return $query;
    }

    private function fetchCachedAttendances(User $user, array $filters): array
    {
        if (! empty($filters['date'])) {
            return Cache::remember(
                "attendance-{$user->id}-{$filters['date']}",
                now()->addDay(),
                fn () => $this->transformAttendances(
                    Attendance::filter(userId: $user->id, date: $filters['date'])->get()
                )
            ) ?? [];
        }

        if (! empty($filters['week'])) {
            return Cache::remember(
                "attendance-{$user->id}-{$filters['week']}",
                now()->addDay(),
                fn () => $this->transformAttendances(
                    Attendance::filter(userId: $user->id, week: $filters['week'])
                        ->get(['id', 'status', 'date', 'time_in', 'time_out', 'latitude', 'longitude', 'note'])
                )
            ) ?? [];
        }

        if (! empty($filters['month'])) {
            $my = Carbon::parse($filters['month']);

            return Cache::remember(
                "attendance-{$user->id}-{$my->month}-{$my->year}",
                now()->addDay(),
                fn () => $this->transformAttendances(
                    Attendance::filter(userId: $user->id, month: $filters['month'])
                        ->get(['id', 'status', 'date', 'time_in', 'time_out', 'latitude', 'longitude', 'note'])
                )
            ) ?? [];
        }

        return Attendance::where('user_id', $user->id)
            ->get(['id', 'status', 'date', 'time_in', 'time_out', 'latitude', 'longitude', 'note'])
            ->toArray();
    }

    /**
     * Invalidate cached attendance untuk user di tanggal tertentu. Dipanggil
     * setelah clock-in/clock-out supaya admin page langsung lihat update.
     */
    public function clearUserDateCache(User $user, string $date): void
    {
        Cache::forget("attendance-{$user->id}-{$date}");
    }

    private function transformAttendances(Collection $records): array
    {
        return $records->map(function (Attendance $v) {
            $v->setAttribute('coordinates', $v->lat_lng);
            $v->setAttribute('lat', $v->latitude);
            $v->setAttribute('lng', $v->longitude);
            if ($v->shift) {
                $v->setAttribute('shift', $v->shift->name);
            }

            $row = $v->getAttributes();
            // Skor verifikasi wajah (json) dikirim sebagai objek, bukan string.
            foreach (['face_check_in', 'face_check_out'] as $kolom) {
                if (isset($row[$kolom]) && is_string($row[$kolom])) {
                    $row[$kolom] = json_decode($row[$kolom], true);
                }
            }

            return $row;
        })->toArray();
    }

    /** @param array{path:string,sha256:string,size:int,mime:string,width:?int,height:?int} $stored */
    private function mediaAttributes(string $prefix, array $stored, string $pathField): array
    {
        return [
            $pathField => $stored['path'],
            $prefix.'_sha256' => $stored['sha256'],
            $prefix.'_size_bytes' => $stored['size'],
            $prefix.'_mime_type' => $stored['mime'],
            $prefix.'_width' => $stored['width'],
            $prefix.'_height' => $stored['height'],
            $prefix.'_unavailable_reason' => null,
            $prefix.'_reference_fingerprint' => null,
        ];
    }
}
