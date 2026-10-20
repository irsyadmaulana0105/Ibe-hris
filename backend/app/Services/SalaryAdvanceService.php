<?php

namespace App\Services;

use App\Keuangan\Models\Account;
use App\Keuangan\Services\JournalService;
use App\Models\SalaryAdvance;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalaryAdvanceService
{
    // ── Employee ──────────────────────────────────────────────────────────────

    public function submit(User $user, array $data): SalaryAdvance
    {
        return SalaryAdvance::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'date' => $data['date'],
            'amount' => (int) $data['amount'],
            'purpose' => $data['purpose'],
            'status' => 'pending',
        ]);
    }

    public function cancel(User $user, int $id): void
    {
        SalaryAdvance::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->findOrFail($id)
            ->delete();
    }

    public function listForUser(User $user, int $perPage = 10): LengthAwarePaginator
    {
        return SalaryAdvance::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->orderByDesc('date')
            ->paginate($perPage);
    }

    // ── Admin ─────────────────────────────────────────────────────────────────

    public function listForAdmin(User $admin, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = SalaryAdvance::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->with(['user.division'])
            ->orderByRaw("
                CASE status
                    WHEN 'pending' THEN 0
                    WHEN 'approved' THEN 1
                    WHEN 'rejected' THEN 2
                    ELSE 3
                END
            ")
            ->orderBy('date', 'desc');

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['month'])) {
            $d = Carbon::parse($filters['month'].'-01');
            $query->whereYear('date', $d->year)->whereMonth('date', $d->month);
        }
        if (! empty($filters['division'])) {
            $query->whereHas('user', fn ($q) => $q->where('division_id', $filters['division']));
        }
        if (! empty($filters['search'])) {
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%')
            );
        }

        return $query->paginate($perPage);
    }

    public function pendingCount(int $companyId): int
    {
        return SalaryAdvance::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->count();
    }

    public function summaryForAdmin(User $admin, array $filters = []): array
    {
        $query = SalaryAdvance::withoutGlobalScopes()
            ->where('company_id', $admin->company_id);

        if (! empty($filters['month'])) {
            $d = Carbon::parse($filters['month'].'-01');
            $query->whereYear('date', $d->year)->whereMonth('date', $d->month);
        }
        if (! empty($filters['division'])) {
            $query->whereHas('user', fn ($q) => $q->where('division_id', $filters['division']));
        }
        if (! empty($filters['search'])) {
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%')
            );
        }

        $advances = $query->get(['status', 'amount']);

        return [
            'total_requests' => $advances->count(),
            'requested_total' => (int) $advances->sum('amount'),
            'average_request' => $advances->count() > 0
                ? (int) round($advances->avg('amount'))
                : 0,
            'status_counts' => [
                'pending' => $advances->where('status', 'pending')->count(),
                'approved' => $advances->where('status', 'approved')->count(),
                'rejected' => $advances->where('status', 'rejected')->count(),
            ],
        ];
    }

    public function approve(User $admin, int $id, ?string $notes = null): SalaryAdvance
    {
        $advance = $this->approvalQuery($admin)->with('user')->findOrFail($id);
        $this->ensurePending($advance->status);

        $now = now();
        DB::transaction(function () use ($advance, $admin, $notes, $now): void {
            $advance->update([
                'status' => 'approved',
                'notes' => $notes ?: null,
                // Legacy fields remain populated for old reports and clients.
                'approved_by' => $admin->id,
                'approved_at' => $now,
                'hr_approved_by' => $admin->id,
                'hr_approved_at' => $now,
                'hr_notes' => $notes,
            ]);
            app(AuditLogService::class)->log(
                'salary_advance.hr_approved',
                $admin,
                'salary_advance',
                $advance->id,
                [
                    'user_id' => $advance->user_id,
                    'status' => 'approved',
                    'amount' => $advance->amount,
                    'notes' => $notes,
                    'next_step' => 'finance_settlement',
                ]
            );
            // Compatibility event consumed by existing inbox/report clients.
            app(AuditLogService::class)->log(
                'salary_advance.approved',
                $admin,
                'salary_advance',
                $advance->id,
                ['user_id' => $advance->user_id, 'status' => 'approved', 'amount' => $advance->amount]
            );
        });
        if ($advance->user) {
            app(NotificationDispatcher::class)->dispatch('kasbon_approved', $advance->user, [
                'amount' => number_format((float) $advance->amount, 0, ',', '.'),
                'next_step' => 'Menunggu pencairan oleh Finance.',
            ]);
        }

        return $advance->fresh();
    }

    /**
     * Finance settlement. A posted journal is a hard gate: no journal means no
     * status transition and no partial settlement record.
     */
    public function settle(User $finance, int $id, string $reference): SalaryAdvance
    {
        $advance = $this->approvalQuery($finance)->with('user')->findOrFail($id);

        if ($advance->status !== 'approved') {
            throw ValidationException::withMessages([
                'status' => 'Kasbon harus disetujui HR sebelum dapat dicairkan Finance.',
            ]);
        }

        $hrApproverId = $advance->hr_approved_by ?? $advance->approved_by;
        if ($hrApproverId && (string) $hrApproverId === (string) $finance->id) {
            throw ValidationException::withMessages([
                'segregation_of_duties' => 'Penyetuju HR tidak boleh mencairkan kasbon yang sama.',
            ]);
        }

        DB::transaction(function () use ($advance, $finance, $reference): void {
            $journalId = $this->createDisbursementJournal($finance, $advance);
            if (! $journalId) {
                throw ValidationException::withMessages([
                    'journal' => 'Pencairan dibatalkan karena jurnal kasbon tidak dapat dibuat dan diposting.',
                ]);
            }

            $advance->update([
                'status' => 'paid',
                'settled_by' => $finance->id,
                'settled_at' => now(),
                'payment_reference' => $reference,
                'journal_id' => $journalId,
            ]);

            app(AuditLogService::class)->log(
                'salary_advance.settled',
                $finance,
                'salary_advance',
                $advance->id,
                [
                    'user_id' => $advance->user_id,
                    'status' => 'paid',
                    'amount' => $advance->amount,
                    'reference' => $reference,
                    'journal_id' => $journalId,
                    'hr_approved_by' => $advance->hr_approved_by ?? $advance->approved_by,
                ]
            );
        });

        return $advance->fresh();
    }

    /**
     * Auto-create posted Journal Entry: DR Piutang Karyawan, CR Kas.
     * Akun Piutang Karyawan (1160) auto-create kalau belum ada di CoA company ini
     * (sub-akun dari 1100 Aset Lancar).
     */
    private function createDisbursementJournal(User $admin, SalaryAdvance $advance): string
    {
        $companyId = (int) $advance->company_id;
        $amount = (int) $advance->amount;
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal kasbon harus lebih dari nol.']);
        }

        // Resolve Piutang Karyawan (1160). Kalau belum ada, auto-create.
        $receivableAcc = Account::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('code', '1160')
            ->first();
        if (! $receivableAcc) {
            $parent = Account::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('code', '1100')
                ->first();
            $receivableAcc = Account::create([
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

        // Cash account 1110 - kalau tidak ada, skip journal (warning di log)
        $cashAcc = Account::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('code', '1110')
            ->first();
        if (! $cashAcc) {
            throw ValidationException::withMessages([
                'journal' => "Akun 1110 Kas belum tersedia untuk company {$companyId}.",
            ]);
        }

        $userName = $advance->user?->name ?? 'karyawan';
        $journal = app(JournalService::class)->create($admin, [
            'date' => now()->toDateString(),
            'description' => "Kasbon (salary advance) - {$userName}",
            'reference' => "KASBON-{$advance->id}",
        ], [
            [
                'account_id' => $receivableAcc->id,
                'debit' => $amount,
                'credit' => 0,
                'memo' => "Piutang kasbon - {$userName}",
            ],
            [
                'account_id' => $cashAcc->id,
                'debit' => 0,
                'credit' => $amount,
                'memo' => "Pencairan kasbon ke {$userName}",
            ],
        ]);
        app(JournalService::class)->post($admin, $journal);

        if (! $journal->fresh()?->posted_at) {
            throw ValidationException::withMessages(['journal' => 'Jurnal kasbon gagal diposting.']);
        }

        return (string) $journal->id;
    }

    public function reject(User $admin, int $id, ?string $notes = null): SalaryAdvance
    {
        $advance = $this->approvalQuery($admin)->with('user')->findOrFail($id);
        $this->ensurePending($advance->status);
        $advance->update([
            'status' => 'rejected',
            'notes' => $notes ?: null,
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);
        app(AuditLogService::class)->log(
            'salary_advance.rejected',
            $admin,
            'salary_advance',
            $advance->id,
            [
                'user_id' => $advance->user_id,
                'status' => 'rejected',
                'amount' => $advance->amount,
                'notes' => $notes,
            ]
        );
        if ($advance->user) {
            app(NotificationDispatcher::class)->dispatch('kasbon_rejected', $advance->user, [
                'amount' => number_format((float) $advance->amount, 0, ',', '.'),
                'rejection_reason' => $notes ?? 'Tidak disebutkan',
            ]);
        }

        return $advance->fresh();
    }

    public function detail(User $admin, int $id): SalaryAdvance
    {
        return $this->approvalQuery($admin)
            ->with(['user.division', 'approvedBy', 'hrApprovedBy', 'settledBy'])
            ->findOrFail($id);
    }

    private function approvalQuery(User $admin)
    {
        return SalaryAdvance::withoutGlobalScopes()->where(function ($query) use ($admin) {
            $query->where('company_id', $admin->company_id)
                ->orWhere(function ($legacy) use ($admin) {
                    $legacy->whereNull('company_id')->whereHas('user', fn ($user) => $user->withoutGlobalScopes()
                        ->where('company_id', $admin->company_id));
                });
        });
    }

    private function ensurePending(string $status): void
    {
        if ($status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Kasbon hanya dapat diproses saat berstatus pending.']);
        }
    }

    /**
     * Hapus permanen pengajuan kasbon. Dibatasi Finance lewat capability di
     * controller.
     *
     * Baris yang sudah masuk jurnal TIDAK boleh dihapus: jurnalnya akan jadi
     * yatim dan buku besar tidak lagi seimbang. Jalur yang benar untuk kasus itu
     * adalah membuat jurnal balik lebih dulu, baru menghapus barisnya.
     */
    public function adminDelete(User $finance, int $id, ?string $reason = null): void
    {
        $advance = SalaryAdvance::withoutGlobalScopes()
            ->where('company_id', $finance->company_id)
            ->findOrFail($id);

        if ($advance->journal_id) {
            // Jurnal yang masih aktif (draft/posted) memblokir penghapusan: kalau
            // barisnya hilang, jurnalnya jadi yatim dan buku besar tidak seimbang.
            // Jurnal yang SUDAH di-reverse tidak memblokir - dampak keuangannya
            // sudah nol dan jejak auditnya tetap tersimpan di jurnal balik.
            $journal = \App\Keuangan\Models\Journal::query()->find($advance->journal_id);
            if (! $journal || $journal->status !== 'reversed') {
                throw ValidationException::withMessages([
                    'id' => __('Kasbon ini sudah masuk jurnal keuangan dan tidak bisa dihapus. Buat jurnal balik dulu untuk membatalkannya.'),
                ]);
            }
        }

        if ($advance->status === 'paid' || $advance->settled_at) {
            throw ValidationException::withMessages([
                'id' => __('Kasbon yang sudah dicairkan tidak bisa dihapus.'),
            ]);
        }

        $snapshot = [
            'user_id' => $advance->user_id,
            'status' => $advance->status,
            'date' => (string) $advance->date,
            'amount' => $advance->amount,
            'purpose' => $advance->purpose,
            'reason' => $reason,
        ];

        DB::transaction(function () use ($advance): void {
            $advance->delete();
        });

        // Dicatat setelah commit: barisnya sudah hilang, jadi snapshot di atas
        // adalah satu-satunya jejak isinya.
        app(AuditLogService::class)->log(
            'salary_advance.deleted',
            $finance,
            'salary_advance',
            $id,
            $snapshot
        );
    }
}
