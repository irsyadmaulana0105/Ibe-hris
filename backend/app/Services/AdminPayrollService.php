<?php

namespace App\Services;

use App\Keuangan\Models\Account;
use App\Keuangan\Services\JournalService;
use App\Models\Payroll;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminPayrollService
{
    public function __construct(private readonly PayrollService $payrolls) {}

    public function selfIndexForEmployee(string $userId, array $filters = []): LengthAwarePaginator
    {
        // Karyawan hanya bisa lihat payroll yang sudah dibayar + bukti transfer ter-upload.
        return Payroll::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->where('status', 'paid')
            ->whereNotNull('transfer_proof_path')
            ->with(['user.division', 'user.jobTitle'])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->paginate((int) ($filters['per_page'] ?? 12));
    }

    public function paginateForAdmin(User $admin, array $filters = []): LengthAwarePaginator
    {
        [$month, $year] = $this->resolveMonthYear($filters['month'] ?? null);

        $query = Payroll::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->where('month', $month)
            ->where('year', $year)
            ->with(['user.division', 'user.jobTitle'])
            ->orderByDesc('id');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('nip', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate((int) ($filters['per_page'] ?? 10));
    }

    public function references(User $admin): array
    {
        return [
            'employees' => User::query()
                ->where('company_id', $admin->company_id)
                ->where('group', 'user')
                ->active()
                ->orderBy('name')
                ->get(['id', 'name', 'nip', 'email', 'division_id', 'job_title_id']),
            'statuses' => [
                'draft' => 'Draft',
                'approved' => 'Approved',
                'paid' => 'Paid',
            ],
        ];
    }

    public function summary(User $admin, ?string $monthValue = null): array
    {
        [$month, $year] = $this->resolveMonthYear($monthValue);

        $aggregate = Payroll::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->where('month', $month)
            ->where('year', $year)
            ->selectRaw('COUNT(*) as payroll_count')
            ->selectRaw('COALESCE(SUM(gross_total), 0) as gross_total')
            ->selectRaw('COALESCE(SUM(deduction_total), 0) as deduction_total')
            ->selectRaw('COALESCE(SUM(net_total), 0) as net_total')
            ->first();

        $statusCounts = Payroll::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->where('month', $month)
            ->where('year', $year)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'payroll_count' => (int) ($aggregate?->payroll_count ?? 0),
            'gross_total' => (int) ($aggregate?->gross_total ?? 0),
            'deduction_total' => (int) ($aggregate?->deduction_total ?? 0),
            'net_total' => (int) ($aggregate?->net_total ?? 0),
            'status_counts' => [
                'draft' => (int) ($statusCounts['draft'] ?? 0),
                'approved' => (int) ($statusCounts['approved'] ?? 0),
                'paid' => (int) ($statusCounts['paid'] ?? 0),
            ],
        ];
    }

    public function exportPeriodReport(User $admin, array $filters = []): StreamedResponse
    {
        [$month, $year] = $this->resolveMonthYear($filters['month'] ?? null);

        $payrolls = Payroll::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->where('month', $month)
            ->where('year', $year)
            ->with(['user.division', 'user.jobTitle'])
            ->orderBy('id')
            ->get();

        $summary = $this->summary($admin, sprintf('%04d-%02d', $year, $month));
        $fileName = sprintf('payroll-report-%04d-%02d.csv', $year, $month);

        return response()->streamDownload(function () use ($payrolls, $summary, $month, $year): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, ['Payroll Period Report']);
            fputcsv($handle, ['Period', sprintf('%02d/%04d', $month, $year)]);
            fputcsv($handle, ['Total Payroll', $summary['payroll_count']]);
            fputcsv($handle, ['Total Gross', $summary['gross_total']]);
            fputcsv($handle, ['Total Deduction', $summary['deduction_total']]);
            fputcsv($handle, ['Total Net', $summary['net_total']]);
            fputcsv($handle, ['Draft', $summary['status_counts']['draft']]);
            fputcsv($handle, ['Approved', $summary['status_counts']['approved']]);
            fputcsv($handle, ['Paid', $summary['status_counts']['paid']]);
            fputcsv($handle, []);
            fputcsv($handle, [
                'Payroll Number',
                'Employee Name',
                'NIP',
                'Email',
                'Division',
                'Job Title',
                'Status',
                'Attendance Count',
                'Attendance Percentage',
                'Gross Total',
                'Deduction Total',
                'Net Total',
            ]);

            foreach ($payrolls as $payroll) {
                fputcsv($handle, [
                    $payroll->payroll_number,
                    $payroll->user?->name,
                    $payroll->user?->nip,
                    $payroll->user?->email,
                    $payroll->user?->division?->name,
                    $payroll->user?->jobTitle?->name,
                    $payroll->status,
                    $payroll->attendance_count,
                    $payroll->attendance_percentage,
                    $payroll->gross_total,
                    $payroll->deduction_total,
                    $payroll->net_total,
                ]);
            }

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function generate(User $admin, string $employeeId, string $monthValue, int $bonusExtra = 0): Payroll
    {
        $employee = $this->findEmployee($admin, $employeeId);
        [$month, $year] = $this->resolveMonthYear($monthValue);

        $payroll = $this->payrolls->calculate($employee, $month, $year, $bonusExtra);
        $payroll->update(['generated_by' => $admin->id]);

        app(AuditLogService::class)->log(
            'payroll.generated',
            $admin,
            'payroll',
            $payroll->id,
            [
                'target_user_id' => $employee->id,
                'month' => $month,
                'year' => $year,
                'bonus_extra' => $bonusExtra,
            ]
        );

        return $payroll->load(['user.division', 'user.jobTitle']);
    }

    /**
     * Generate payroll for selected users - atau semua karyawan aktif kalau
     * $userIds = null. Memungkinkan HR pilih per-orang dari UI.
     */
    public function generateBulk(User $admin, string $monthValue, int $bonusExtra = 0, ?array $userIds = null): Collection
    {
        [$month, $year] = $this->resolveMonthYear($monthValue);
        $generated = $this->payrolls->calculateBulk($admin->company_id, $month, $year, $bonusExtra, $userIds);

        app(AuditLogService::class)->log(
            'payroll.bulk_generated',
            $admin,
            'payroll',
            null,
            [
                'month' => $month,
                'year' => $year,
                'bonus_extra' => $bonusExtra,
                'generated_count' => $generated->count(),
                'user_ids' => $userIds,  // null = semua, array = subset
            ]
        );

        return $generated;
    }

    /**
     * HR submits a draft payroll to Finance.
     * draft → submitted. Superadmin can also submit (bypass via payroll.manage).
     */
    public function submit(User $admin, int $id): Payroll
    {
        $payroll = $this->findForAdmin($admin, $id);

        if ($payroll->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => 'Hanya payroll draft yang dapat diajukan.',
            ]);
        }

        $payroll->update(['status' => 'submitted', 'submitted_by' => $admin->id]);

        app(AuditLogService::class)->log(
            'payroll.submitted',
            $admin,
            'payroll',
            $payroll->id,
            ['payroll_id' => $payroll->id, 'status' => 'submitted'],
        );

        return $payroll->fresh()->load(['user.division', 'user.jobTitle']);
    }

    /**
     * Bulk submit all draft payrolls for a period.
     * Returns count of affected rows.
     */
    public function submitBulk(User $admin, string $monthValue): int
    {
        [$month, $year] = $this->resolveMonthYear($monthValue);

        $count = Payroll::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->where('month', $month)
            ->where('year', $year)
            ->where('status', 'draft')
            ->update(['status' => 'submitted', 'submitted_by' => $admin->id]);

        app(AuditLogService::class)->log(
            'payroll.bulk_submitted',
            $admin,
            'payroll',
            null,
            ['month' => $month, 'year' => $year, 'submitted_count' => $count],
        );

        return (int) $count;
    }

    /**
     * Finance approves a submitted payroll. Superadmin may approve from any
     * non-terminal status via payroll.manage capability.
     */
    public function approve(User $admin, int $id): Payroll
    {
        $payroll = $this->findForAdmin($admin, $id);

        // Finance / Superadmin dapat menyetujui payroll draft atau submitted.
        // Payroll yang sudah approved/paid tidak dapat diapprove ulang.
        if (! in_array($payroll->status, ['draft', 'submitted'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Hanya payroll draft/submitted yang dapat disetujui.',
            ]);
        }

        $payroll->update(['status' => 'approved', 'approved_by' => $admin->id]);

        app(AuditLogService::class)->log(
            'payroll.approved',
            $admin,
            'payroll',
            $payroll->id,
            ['payroll_id' => $payroll->id, 'status' => 'approved'],
        );

        $fresh = $payroll->fresh()->load(['user.division', 'user.jobTitle']);
        if ($fresh->user) {
            app(NotificationDispatcher::class)->dispatch('payroll_approved', $fresh->user, [
                'period' => sprintf('%04d-%02d', $fresh->year, $fresh->month),
                'net_pay' => number_format((float) $fresh->net_total, 0, ',', '.'),
            ]);
        }

        return $fresh;
    }

    public function markPaid(User $admin, int $id, ?UploadedFile $proofFile = null, ?string $reference = null): Payroll
    {
        $proofPath = null;

        try {
            $fresh = DB::transaction(function () use ($admin, $id, $proofFile, $reference, &$proofPath): Payroll {
                $payroll = Payroll::withoutGlobalScopes()
                    ->where('company_id', $admin->company_id)
                    ->with(['user.division', 'user.jobTitle'])
                    ->lockForUpdate()
                    ->findOrFail($id);

                if ($payroll->status !== 'approved') {
                    throw ValidationException::withMessages([
                        'status' => 'Hanya payroll approved yang dapat ditandai sudah ditransfer.',
                    ]);
                }

                if ($payroll->journal_id !== null) {
                    throw ValidationException::withMessages([
                        'journal' => 'Payroll approved ini sudah memiliki jurnal. Rekonsiliasi diperlukan sebelum pembayaran.',
                    ]);
                }

                // File storage is not transactional. Use a unique name and delete it
                // in the outer catch whenever the database transaction rolls back.
                if ($proofFile) {
                    $year = $payroll->year;
                    $month = str_pad((string) $payroll->month, 2, '0', STR_PAD_LEFT);
                    $dir = "payroll-proofs/{$payroll->company_id}/{$year}-{$month}";
                    $cleanName = "{$payroll->id}_".Str::ulid().'_'.preg_replace(
                        '/[^A-Za-z0-9._-]/',
                        '_',
                        $proofFile->getClientOriginalName(),
                    );
                    $storedPath = $proofFile->storeAs($dir, $cleanName, 'local');
                    if (! is_string($storedPath) || $storedPath === '') {
                        throw ValidationException::withMessages([
                            'transfer_proof' => 'Bukti transfer tidak dapat disimpan.',
                        ]);
                    }
                    $proofPath = $storedPath;
                }

                // Journal creation and posting are a hard accounting gate. The
                // payroll state changes only in the same transaction as the journal.
                $journalId = $this->createPayrollJournal($admin, $payroll);

                $updates = [
                    'status' => 'paid',
                    'paid_by' => $admin->id,
                    'journal_id' => $journalId,
                    'transfer_reference' => $reference,
                ];
                if ($proofPath) {
                    $updates['transfer_proof_path'] = $proofPath;
                    $updates['transfer_proof_uploaded_at'] = now();
                    $updates['transfer_proof_uploaded_by'] = $admin->id;
                }
                $payroll->update($updates);

                app(AuditLogService::class)->log(
                    'payroll.paid',
                    $admin,
                    'payroll',
                    $payroll->id,
                    [
                        'payroll_id' => $payroll->id,
                        'status' => 'paid',
                        'journal_id' => $journalId,
                    ]
                );

                return $payroll->fresh()->load(['user.division', 'user.jobTitle']);
            });
        } catch (\Throwable $e) {
            if ($proofPath) {
                Storage::disk('local')->delete($proofPath);
            }

            Log::error('Payroll markPaid rolled back', [
                'payroll_id' => $id,
                'error' => $e->getMessage(),
            ]);

            if ($e instanceof ValidationException) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'journal' => 'Pembayaran payroll dibatalkan karena jurnal tidak dapat dibuat dan diposting.',
            ]);
        }

        // Notification is post-commit and must never turn a committed payment
        // into a 5xx response that a client could retry as a new mutation.
        if ($fresh->user) {
            try {
                app(NotificationDispatcher::class)->dispatch('payroll_ready', $fresh->user, [
                    'period' => sprintf('%04d-%02d', $fresh->year, $fresh->month),
                    'net_pay' => number_format((float) $fresh->net_total, 0, ',', '.'),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Payroll paid notification failed after commit', [
                    'payroll_id' => $fresh->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $fresh;
    }

    /**
     * Auto-create posted Journal Entry untuk payroll yang baru saja di-markPaid.
     *
     * Skema:
     *   DR 5210 Beban Gaji              = gross_total
     *   CR 1120 Bank                    = net_total
     *   CR 1160 Piutang Karyawan        = salary_advance_payment (potongan kasbon kalau ada)
     *   CR 2130 Hutang Pajak (PPh21/PPN) = sisa potongan (pajak + BPJS + late/absent)
     *
     * Akun yang missing di CoA akan auto-create (1160 Piutang Karyawan).
     * Akun wajib yang hilang atau journal yang gagal posted membatalkan payment.
     */
    private function createPayrollJournal(User $admin, Payroll $payroll): string
    {
        $companyId = (int) $payroll->company_id;
        $gross = (int) $payroll->gross_total;
        $net = (int) $payroll->net_total;
        $kasbon = (int) ($payroll->salary_advance_payment ?? 0);
        $otherDeductions = $gross - $net - $kasbon;

        if ($gross <= 0) {
            throw ValidationException::withMessages([
                'journal' => 'Gross payroll harus lebih dari nol sebelum pembayaran.',
            ]);
        }

        $resolveAcc = fn (string $code) => Account::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->first();

        $debitSalary = $resolveAcc('5210');
        $creditBank = $resolveAcc('1120');
        if (! $debitSalary || ! $creditBank) {
            Log::error(
                "Payroll journal skipped - akun 5210 Beban Gaji atau 1120 Bank tidak ada di company {$companyId}"
            );

            throw ValidationException::withMessages([
                'journal' => 'Akun 5210 Beban Gaji dan 1120 Bank wajib dikonfigurasi sebelum pembayaran payroll.',
            ]);
        }

        // Resolve Piutang Karyawan (1160) - auto-create kalau belum ada
        $receivable = null;
        if ($kasbon > 0) {
            $receivable = $resolveAcc('1160');
            if (! $receivable) {
                $parent = $resolveAcc('1100');
                $receivable = Account::create([
                    'company_id' => $companyId,
                    'code' => '1160',
                    'name' => 'Piutang Karyawan',
                    'type' => 'asset',
                    'normal_balance' => 'debit',
                    'parent_id' => $parent?->id,
                    'is_header' => false,
                    'is_active' => true,
                    'description' => 'Auto-generated untuk kasbon karyawan',
                ]);
            }
        }

        $taxLiability = $otherDeductions > 0 ? $resolveAcc('2130') : null;

        $userName = $payroll->user?->name ?? 'karyawan';
        $period = sprintf('%04d-%02d', $payroll->year, $payroll->month);

        $lines = [
            [
                'account_id' => $debitSalary->id,
                'debit' => $gross,
                'credit' => 0,
                'memo' => "Beban gaji {$userName} periode {$period}",
            ],
            [
                'account_id' => $creditBank->id,
                'debit' => 0,
                'credit' => $net,
                'memo' => "Transfer net salary ke {$userName}",
            ],
        ];

        if ($kasbon > 0 && $receivable) {
            $lines[] = [
                'account_id' => $receivable->id,
                'debit' => 0,
                'credit' => $kasbon,
                'memo' => "Pelunasan kasbon {$userName} (potong gaji)",
            ];
        }

        // Sisa potongan (PPh21 + BPJS + late/absent) → "Hutang Pajak". Kalau akun
        // 2130 tidak ada, atau sisa <= 0, skip baris ini (akan terdeteksi unbalance,
        // tapi kita validate sebelum kirim).
        if ($otherDeductions > 0 && $taxLiability) {
            $lines[] = [
                'account_id' => $taxLiability->id,
                'debit' => 0,
                'credit' => $otherDeductions,
                'memo' => "Potongan pajak/BPJS/late {$userName} (akan dibayarkan terpisah)",
            ];
        } elseif ($otherDeductions > 0) {
            Log::error(
                "Payroll journal skipped (payroll {$payroll->id}) - akun 2130 tidak ada, sisa potongan Rp {$otherDeductions}"
            );

            throw ValidationException::withMessages([
                'journal' => 'Akun 2130 Hutang Pajak wajib dikonfigurasi untuk payroll dengan potongan.',
            ]);
        }

        // Total debit = $gross. Total credit = $net + $kasbon + $otherDeductions = $gross. ✓
        $journal = app(JournalService::class)->create($admin, [
            'date' => now()->toDateString(),
            'description' => "Payroll {$userName} periode {$period}",
            'reference' => $payroll->payroll_number ?? "PAYROLL-{$payroll->id}",
        ], $lines);

        $postedJournal = app(JournalService::class)->post($admin, $journal);
        if ($postedJournal->status !== 'posted') {
            throw ValidationException::withMessages([
                'journal' => 'Jurnal payroll gagal mencapai status posted.',
            ]);
        }

        return (string) $postedJournal->id;
    }

    public function findForAdmin(User $admin, int $id): Payroll
    {
        return Payroll::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->with(['user.division', 'user.jobTitle'])
            ->findOrFail($id);
    }

    /**
     * @return array{0:int,1:int}
     */
    private function resolveMonthYear(?string $monthValue): array
    {
        $date = $monthValue ? Carbon::parse($monthValue.'-01') : now();

        return [(int) $date->month, (int) $date->year];
    }

    private function findEmployee(User $admin, string $employeeId): User
    {
        $employee = User::query()
            ->where('company_id', $admin->company_id)
            ->where('group', 'user')
            ->find($employeeId);

        if (! $employee) {
            throw ValidationException::withMessages([
                'user_id' => 'Karyawan tidak ditemukan untuk perusahaan ini.',
            ]);
        }

        return $employee;
    }
}
