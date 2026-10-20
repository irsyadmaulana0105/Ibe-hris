<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\DocumentCounter;
use App\Models\LateDeductionTier;
use App\Models\Overtime;
use App\Models\Payroll;
use App\Models\Reimbursement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PayrollService
{
    /**
     * Calculate or recalculate payroll for a user in a given month/year.
     * Creates/updates the Payroll record and returns it.
     */
    public function calculate(User $user, int $month, int $year, int $bonusExtra = 0): Payroll
    {
        ['start' => $start, 'end' => $end] = $this->getPayrollPeriod($month, $year);

        [
            'late_count' => $lateCount,
            'present_count' => $presentCount,
            'absent_count' => $absentCount,
            'leave_count' => $leaveCount,
        ] = $this->getAttendanceSummaryForUser($user->id, $start, $end);

        $workingDays         = $this->countWorkingDays($start, $end);
        $attendancePercentage = $workingDays > 0
            ? round(($presentCount / $workingDays) * 100, 2)
            : 0;

        // ── Approved overtime hours ──────────────────────────────────────────
        $overtimeHours = Overtime::where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->sum('total_hours');

        // ── Approved reimbursements ──────────────────────────────────────────
        $reimbursementTotal = Reimbursement::where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->sum('approved_amount');

        // ── Salary rates (from user, fallback to grade allowance) ───────────
        //
        // Training: gaji = daily_rate × jumlah hari hadir DALAM RENTANG training.
        // Tanpa filter rentang, hari hadir SEBELUM training mulai atau SETELAH
        // training berakhir (tapi masih dalam periode payroll) ikut ke-count
        // → over-bayar. Contoh: training 22-26 Jun (5 hari), tapi presentCount
        // hitung 28 May - 27 Jun (30 hari window) → bisa 6+ hari hadir.
        //
        // Probation: setengah salary_monthly (existing rule).
        // Permanent + others: full salary_monthly.
        $isTraining = $user->employment_status === 'training';
        if ($isTraining) {
            $dailyRate = (int) ($user->daily_rate ?? 0);
            // Intersect payroll period dengan training period
            $trainingStart = $user->training_started_at
                ? Carbon::parse($user->training_started_at)->startOfDay()
                : $start;
            $trainingEnd = $user->training_ends_at
                ? Carbon::parse($user->training_ends_at)->endOfDay()
                : $end;
            $rangeStart = $trainingStart->greaterThan($start) ? $trainingStart : $start;
            $rangeEnd   = $trainingEnd->lessThan($end) ? $trainingEnd : $end;

            // Re-query attendance hanya untuk rentang training intersection
            $trainingPresent = 0;
            if ($rangeStart->lessThanOrEqualTo($rangeEnd)) {
                $trainingPresent = $this->getAttendanceSummaryForUser(
                    $user->id, $rangeStart, $rangeEnd
                )['present_count'] ?? 0;
            }
            $baseSalary = $dailyRate * $trainingPresent;
            // Override presentCount agar slip tampilkan hari yang BENAR (=
            // hari hadir dalam training) bukan periode penuh
            $presentCount = $trainingPresent;
        } else {
            $baseSalary = (int) ($user->salary_monthly ?? 0);
            if ($user->employment_status === 'probation') {
                $baseSalary = (int) floor($baseSalary / 2);
            }
        }

        $mealTransportRate = (int) ($user->meal_transport_rate ?? 0);
        if ($mealTransportRate === 0 && $user->employeeGrade) {
            $allowance = $user->employeeGrade->allowance()->first();
            if ($allowance) {
                $mealTransportRate = (int) ($allowance->meal_allowance + $allowance->transport_allowance);
            }
        }

        $overtimeRate        = (int) ($user->overtime_rate ?? 0);
        $attendanceRate      = (int) ($user->attendance_allowance ?? 0);
        $lateRate            = (int) ($user->late_deduction ?? 0);
        $absentRate          = (int) ($user->absent_deduction ?? 0);
        $leaveRate           = (int) ($user->leave_deduction ?? 0);
        $bonusRate           = (int) ($user->bonus_rate ?? 0);
        $thrRate             = (int) ($user->thr_allowance ?? 0);
        $salaryAdvBalance    = (int) ($user->salary_advance_balance ?? 0);

        // ── Earnings ─────────────────────────────────────────────────────────
        $mealTransportTotal = $mealTransportRate * $presentCount;
        $overtimeTotal      = (int) round($overtimeHours * $overtimeRate);
        $bonusTotal         = $bonusRate + $bonusExtra;
        $thrTotal           = $thrRate;                // admin sets manually if applicable
        // Bonus kehadiran rule - sinkron dengan kontrak kerja IBE Reality
        // (Pasal 3 ayat 3): hadir ≥ N hari kerja DAN telat ≤ M× sebulan.
        // Threshold dibaca dari companies.attendance_bonus_min_present (default 25)
        // dan attendance_bonus_max_late (default 3), agar bisa di-tweak admin tanpa
        // ubah kode kalau kontrak di-revisi.
        $company = $user->company;
        $minPresent = (int) ($company->attendance_bonus_min_present ?? 25);
        $maxLate    = (int) ($company->attendance_bonus_max_late ?? 3);

        $attendanceBonusNote = null;
        if ($presentCount < $minPresent) {
            $attendanceTotal     = 0;
            $attendanceBonusNote = "Bonus kehadiran hangus: hadir {$presentCount} hari (minimal {$minPresent}). Sesuai kontrak Pasal 3.";
        } elseif ($lateCount > $maxLate) {
            $attendanceTotal     = 0;
            $attendanceBonusNote = "Bonus kehadiran hangus: telat {$lateCount}× (maksimal {$maxLate}×). Sesuai kontrak Pasal 3 & 4.";
        } else {
            $attendanceTotal     = $attendanceRate;
        }

        // ── Deductions ────────────────────────────────────────────────────────
        // Late deduction - pakai tier kalau ada (mengevaluasi setiap kejadian
        // berdasarkan late_minutes-nya), fallback flat user->late_deduction kalau
        // belum di-setup tier sama sekali.
        $lateTotal = $this->calcLateTotalWithTiers($user, $start, $end, $lateRate);
        $absentTotal = $absentRate * $absentCount;
        $leaveTotal  = $leaveRate * $leaveCount;

        // Salary advance repayment: deduct full balance if net allows
        $grossEstimate = $baseSalary + $mealTransportTotal + $overtimeTotal
            + $bonusTotal + $thrTotal + $attendanceTotal + (int) $reimbursementTotal;
        $deductionsExAdv = $lateTotal + $absentTotal + $leaveTotal;
        $remainingAfterDed = $grossEstimate - $deductionsExAdv;
        $salaryAdvPayment = min($salaryAdvBalance, max(0, $remainingAfterDed));

        // ── Hardcoded base totals ─────────────────────────────────────────────
        $grossBase     = $baseSalary + $mealTransportTotal + $overtimeTotal
            + $bonusTotal + $thrTotal + $attendanceTotal + (int) $reimbursementTotal;
        $deductionBase = $lateTotal + $absentTotal + $leaveTotal + $salaryAdvPayment;

        // ── Custom formula components (admin-defined via Settings → Payroll Formula) ───
        // The evaluator only picks up rows with is_default=false so seeded base
        // components don't double-count. Errors are logged & treated as 0.
        $evaluatorContext = [
            'basic_salary'    => $baseSalary,
            'working_days'    => $presentCount,
            'overtime_hours'  => (int) $overtimeHours,
            'meal_transport'  => $mealTransportTotal,
            'thr'             => $thrTotal,
            'bonus'           => $bonusTotal,
            'reimbursement'   => (int) $reimbursementTotal,
            'gross'           => $grossBase,
            'taxable_income'  => max(0, $grossBase * 12 - 54_000_000), // annual minus PTKP TK/0
        ];

        $customEarnings   = app(PayrollFormulaEvaluator::class)
            ->evaluateCategory($user->company_id, 'earning', $evaluatorContext);

        // Second pass for deductions sees the updated gross (incl. custom earnings)
        $evaluatorContext['gross'] = $grossBase + $customEarnings['total'];
        $customDeductions = app(PayrollFormulaEvaluator::class)
            ->evaluateCategory($user->company_id, 'deduction', $evaluatorContext);

        // ── Final totals ─────────────────────────────────────────────────────
        $grossTotal     = $grossBase + (int) round($customEarnings['total']);
        $deductionTotal = $deductionBase + (int) round($customDeductions['total']);
        $netTotal       = $grossTotal - $deductionTotal;

        // ── Payroll number ───────────────────────────────────────────────────
        $payrollNumber = $this->getOrGenerateNumber($user, $month, $year);

        // ── Persist ───────────────────────────────────────────────────────────
        // Cek existing - payroll yang sudah 'paid' adalah final/terminal, skip
        // regenerate supaya gak overwrite data transfer + bukti. Untuk status lain
        // (draft/submitted/approved), regenerate dibolehkan dan status RESET ke
        // draft (perlu re-ajukan-approve karena angka mungkin berubah).
        $existing = Payroll::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('month', $month)
            ->where('year', $year)
            ->first();

        if ($existing && $existing->status === 'paid') {
            // Sudah dibayar - JANGAN sentuh. Return as-is (paid records adalah final).
            return $existing;
        }

        $payload = [
            'company_id'            => $user->company_id,
            'payroll_number'        => $payrollNumber,
            'period_start'          => $start->toDateString(),
            'period_end'            => $end->toDateString(),
            'attendance_percentage' => $attendancePercentage,

            'base_salary'           => $baseSalary,
            'payroll_basis'         => $isTraining ? 'daily' : 'monthly',
            'daily_rate_snapshot'   => $isTraining ? (int) ($user->daily_rate ?? 0) : null,
            'meal_transport_total'  => $mealTransportTotal,

            'overtime_count'        => (int) $overtimeHours,
            'overtime_rate'         => $overtimeRate,
            'overtime_total'        => $overtimeTotal,

            'attendance_count'      => $presentCount,
            'attendance_rate'       => $attendanceRate,
            'attendance_total'      => $attendanceTotal,
            'attendance_bonus_note' => $attendanceBonusNote,

            'bonus_count'           => 1,
            'bonus_rate'            => $bonusRate + $bonusExtra,
            'bonus_total'           => $bonusTotal,

            'thr_count'             => $thrRate > 0 ? 1 : 0,
            'thr_rate'              => $thrRate,
            'thr_total'             => $thrTotal,

            'reimbursement_total'   => (int) $reimbursementTotal,

            'late_count'            => $lateCount,
            'late_rate'             => $lateRate,
            'late_total'            => $lateTotal,

            'absent_count'          => $absentCount,
            'absent_rate'           => $absentRate,
            'absent_total'          => $absentTotal,

            'leave_count'           => $leaveCount,
            'leave_rate'            => $leaveRate,
            'leave_total'           => $leaveTotal,

            'salary_advance_balance' => $salaryAdvBalance,
            'salary_advance_payment' => $salaryAdvPayment,

            'gross_total'           => $grossTotal,
            'deduction_total'       => $deductionTotal,
            'net_total'             => $netTotal,

            'status'                => 'draft',
        ];

        /** @var Payroll $payroll */
        $payroll = Payroll::withoutGlobalScopes()->updateOrCreate(
            ['user_id' => $user->id, 'month' => $month, 'year' => $year],
            $payload,
        );

        return $payroll;
    }

    /**
     * Generate payrolls for all active employees of a company.
     *
     * @return Collection<Payroll>
     */
    /**
     * Bulk generate payroll. $userIds optional filter:
     *   - null → semua karyawan aktif (legacy behavior)
     *   - array → hanya user_ids yang dipilih (HR pick & generate per orang)
     */
    public function calculateBulk(int $companyId, int $month, int $year, int $bonusExtra = 0, ?array $userIds = null): Collection
    {
        // activeDuring(end_of_period): karyawan yang resign DI TENGAH bulan ini
        // TETAP dapat payroll (prorated dari attendance). Karyawan yang resign
        // sebelum bulan ini → skip, sudah tidak relevan.
        ['end' => $end] = $this->getPayrollPeriod($month, $year);

        $query = User::where('company_id', $companyId)
            ->where('group', 'user')
            ->activeDuring($end);

        if ($userIds !== null && count($userIds) > 0) {
            $query->whereIn('id', $userIds);
        }

        return $query->get()->map(fn (User $u) => $this->calculate($u, $month, $year, $bonusExtra));
    }

    /**
     * Resolve payroll period for a selected payroll month.
     *
     * Example: April 2026 => 28 March 2026 until 27 April 2026.
     *
     * @return array{start: Carbon, end: Carbon}
     */
    public function getPayrollPeriod(int $month, int $year): array
    {
        $end = Carbon::create($year, $month, 27)->endOfDay();
        $start = Carbon::create($year, $month, 1)
            ->subMonthNoOverflow()
            ->day(28)
            ->startOfDay();

        return [
            'start' => $start,
            'end' => $end,
        ];
    }

    public function formatPeriodLabel(Carbon $start, Carbon $end): string
    {
        return $start->translatedFormat('d M Y') . ' - ' . $end->translatedFormat('d M Y');
    }

    /**
     * Fetch attendance summaries for one or more users within a payroll period.
     *
     * @param iterable<int, string> $userIds
     * @return array<string, array{late_count:int,present_count:int,absent_count:int,leave_count:int}>
     */
    public function getAttendanceSummariesForUsers(iterable $userIds, Carbon $start, Carbon $end): array
    {
        $userIds = collect($userIds)
            ->filter(fn ($userId) => filled($userId))
            ->values()
            ->all();

        if ($userIds === []) {
            return [];
        }

        $attendances = Attendance::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get(['id', 'user_id', 'date', 'status']);

        $summaries = $attendances
            ->groupBy('user_id')
            ->map(fn (Collection $records) => $this->summarizeAttendances($records))
            ->all();

        foreach ($userIds as $userId) {
            $summaries[$userId] ??= $this->emptyAttendanceSummary();
        }

        return $summaries;
    }

    /**
     * Count actual working days (Mon–Fri) in period, excluding public holidays.
     */
    public function countWorkingDays(Carbon $start, Carbon $end): int
    {
        $count  = 0;
        $cursor = $start->copy()->startOfDay();
        $holidays = $this->getHolidayDates($start, $end);

        while ($cursor->lte($end)) {
            if (!$cursor->isWeekend() && !isset($holidays[$cursor->toDateString()])) {
                $count++;
            }
            $cursor->addDay();
        }

        return $count;
    }

    /**
     * Fetch public holiday dates via API (cached 6 h). Returns [Y-m-d => name].
     */
    public function getHolidayDates(Carbon $start, Carbon $end): array
    {
        $months = [];
        $cursor = $start->copy()->startOfMonth();
        while ($cursor->lte($end->copy()->startOfMonth())) {
            $months[] = [$cursor->year, $cursor->month];
            $cursor->addMonth();
        }

        $holidays = [];
        foreach ($months as [$year, $month]) {
            $cacheKey = "holidays-{$year}-{$month}";
            $data = Cache::remember($cacheKey, 60 * 60 * 6, function () use ($year, $month) {
                if (!config('services.holiday_api.enabled', true)) {
                    return [];
                }
                try {
                    $response = Http::timeout((int) config('services.holiday_api.timeout', 8))
                        ->get('https://api-harilibur.vercel.app/api', [
                            'month' => $month,
                            'year'  => $year,
                        ]);
                } catch (\Throwable $e) {
                    report($e);
                    return [];
                }

                if (!$response->ok()) return [];
                $payload = $response->json();
                if (!is_array($payload)) return [];

                $result = [];
                foreach ($payload as $h) {
                    if (!empty($h['holiday_date']) && !empty($h['holiday_name'])) {
                        $result[$h['holiday_date']] = $h['holiday_name'];
                    }
                }
                return $result;
            });

            $holidays = array_merge($holidays, $data);
        }

        return array_filter(
            $holidays,
            fn ($_, $date) => $date >= $start->toDateString() && $date <= $end->toDateString(),
            ARRAY_FILTER_USE_BOTH
        );
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Collapse multiple attendance rows on the same date into one payroll day.
     *
     * Priority is chosen so an actual attendance beats leave/absence data if
     * duplicate rows exist for the same day.
     *
     * @param Collection<int, Attendance> $attendances
     * @return array{late_count:int,present_count:int,absent_count:int,leave_count:int}
     */
    public function summarizeAttendances(Collection $attendances): array
    {
        $dailyStatuses = $attendances
            ->filter(fn (Attendance $attendance) => !empty($attendance->date))
            ->groupBy(fn (Attendance $attendance) => Carbon::parse($attendance->date)->toDateString())
            ->map(fn (Collection $records) => $this->resolveDailyStatus($records));

        return [
            'late_count' => $dailyStatuses->where(fn (string $status) => $status === 'late')->count(),
            'present_count' => $dailyStatuses
                ->where(fn (string $status) => in_array($status, ['present', 'late'], true))
                ->count(),
            'absent_count' => $dailyStatuses->where(fn (string $status) => $status === 'absent')->count(),
            'leave_count' => $dailyStatuses
                ->where(fn (string $status) => in_array($status, ['excused', 'sick'], true))
                ->count(),
        ];
    }

    /**
     * @param Collection<int, Attendance> $records
     */
    private function resolveDailyStatus(Collection $records): string
    {
        $statuses = $records->pluck('status')->filter();

        if ($statuses->contains('late')) {
            return 'late';
        }

        if ($statuses->contains('present')) {
            return 'present';
        }

        if ($statuses->contains('sick')) {
            return 'sick';
        }

        if ($statuses->contains('excused')) {
            return 'excused';
        }

        return 'absent';
    }

    private function getOrGenerateNumber(User $user, int $month, int $year): string
    {
        $existing = Payroll::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('month', $month)
            ->where('year', $year)
            ->value('payroll_number');

        if ($existing) return $existing;

        $counter = DocumentCounter::withoutGlobalScopes()
            ->where('company_id', $user->company_id)
            ->where('name', 'Payroll')
            ->lockForUpdate()
            ->first();

        if (!$counter) {
            return sprintf('GJ/%d/%02d/%05d', $year, $month, rand(1, 99999));
        }

        $counter->increment('counter');
        $counter->refresh();

        return sprintf('%s/%d/%02d/%05d', $counter->prefix ?? 'GJ', $year, $month, $counter->counter);
    }

    /**
     * @return array{late_count:int,present_count:int,absent_count:int,leave_count:int}
     */
    private function getAttendanceSummaryForUser(string $userId, Carbon $start, Carbon $end): array
    {
        return $this->getAttendanceSummariesForUsers([$userId], $start, $end)[$userId]
            ?? $this->emptyAttendanceSummary();
    }

    /**
     * @return array{late_count:int,present_count:int,absent_count:int,leave_count:int}
     */
    private function emptyAttendanceSummary(): array
    {
        return [
            'late_count' => 0,
            'present_count' => 0,
            'absent_count' => 0,
            'leave_count' => 0,
        ];
    }

    /**
     * Sum total potongan terlambat dengan tier-aware lookup.
     *
     * Logic:
     *  1) Load semua attendance bulan ini berstatus 'late' beserta late_minutes
     *  2) Untuk tiap kejadian, cari tier yang match → akumulasi potongan
     *  3) Kalau user belum punya tier (per-user override) DAN company juga belum,
     *     fallback ke flat `users.late_deduction` × count agar legacy data tetap jalan.
     */
    private function calcLateTotalWithTiers(User $user, Carbon $start, Carbon $end, int $flatRate): int
    {
        $lateRecords = Attendance::query()
            ->where('user_id', $user->id)
            ->where('status', 'late')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get(['late_minutes']);

        if ($lateRecords->isEmpty()) return 0;

        $companyHasTiers = LateDeductionTier::query()
            ->where('company_id', $user->company_id)
            ->whereNull('user_id')
            ->exists();
        $userHasTiers = LateDeductionTier::query()
            ->where('user_id', $user->id)
            ->exists();

        // Fallback flat - kalau belum ada tier sama sekali, perilaku lama (flat × count)
        if (!$companyHasTiers && !$userHasTiers) {
            return $flatRate * $lateRecords->count();
        }

        $total = 0;
        foreach ($lateRecords as $r) {
            $minutes = (int) ($r->late_minutes ?? 0);
            // Edge: legacy records (sebelum migration) punya late_minutes=0 walau status=late
            // Treat sebagai 1 menit agar masuk tier pertama (mis. min_minutes=1) - biar
            // gak diam-diam jadi 0 potongan saat tier sudah aktif.
            if ($minutes === 0) $minutes = 1;

            $tier = LateDeductionTier::findForUser($user, $minutes);
            if ($tier) {
                $total += (int) $tier->deduction_amount;
            }
        }
        return $total;
    }
}
