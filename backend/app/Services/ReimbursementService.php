<?php

namespace App\Services;

use App\Exceptions\PrivateAssetIntegrityException;
use App\Keuangan\Models\Account;
use App\Keuangan\Services\JournalService;
use App\Models\Company;
use App\Models\Reimbursement;
use App\Models\ReimbursementCategory;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReimbursementService
{
    public function __construct(private readonly PrivateMediaStorage $privateMedia) {}

    // ── Employee ──────────────────────────────────────────────────────────────

    public function submit(User $user, array $data, UploadedFile $attachment): Reimbursement
    {
        $total = (int) $data['unit_price'] * (int) $data['quantity'];
        $stored = null;
        $reimbursementId = null;
        try {
            return DB::transaction(function () use (
                $user,
                $data,
                $attachment,
                $total,
                &$stored,
                &$reimbursementId,
            ): Reimbursement {
                $reimbursement = Reimbursement::create([
                    'company_id' => $user->company_id,
                    'user_id' => $user->id,
                    'category_id' => $data['category_id'],
                    'date' => $data['date'],
                    'event' => $data['event'],
                    'unit_price' => (int) $data['unit_price'],
                    'quantity' => (int) $data['quantity'],
                    'total_amount' => $total,
                    'status' => 'pending',
                    // Bank info (mandatory di workflow baru - Finance perlu utk transfer)
                    'bank_name' => $data['bank_name'] ?? null,
                    'bank_account_number' => $data['bank_account_number'] ?? null,
                    'bank_account_holder' => $data['bank_account_holder'] ?? $user->name,
                ]);
                $reimbursementId = $reimbursement->id;
                $stored = $this->privateMedia->storeUploaded(
                    (int) $user->company_id,
                    AttendanceReimbursementMediaService::REIMBURSEMENT_CATEGORY,
                    $reimbursement->id,
                    'attachment',
                    $attachment,
                    ['image/jpeg', 'image/png', 'application/pdf'],
                );
                $reimbursement->update($this->mediaAttributes('attachment', $stored, 'attachment_path'));

                return $reimbursement->fresh();
            });
        } catch (\Throwable $failure) {
            if ($stored !== null) {
                $this->privateMedia->deletePrivate(
                    (int) $user->company_id,
                    AttendanceReimbursementMediaService::REIMBURSEMENT_CATEGORY,
                    $reimbursementId,
                    'attachment',
                    $stored['path'],
                );
            }
            throw $failure;
        }
    }

    public function cancel(User $user, int $id): void
    {
        $reimbursement = Reimbursement::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->findOrFail($id);

        $path = $reimbursement->attachment_path;
        $tenantId = (int) ($reimbursement->company_id ?: $user->company_id);
        if ($path === null || $path === '') {
            $reimbursement->delete();

            return;
        }

        if ($this->privateMedia->isPrivatePathFor(
            $tenantId,
            AttendanceReimbursementMediaService::REIMBURSEMENT_CATEGORY,
            $id,
            'attachment',
            $path,
        )) {
            $metadata = $this->mediaMetadata($reimbursement, 'attachment');
            $bytes = $this->privateMedia->readVerified(
                $tenantId,
                AttendanceReimbursementMediaService::REIMBURSEMENT_CATEGORY,
                $id,
                'attachment',
                $path,
                $metadata,
                ['image/jpeg', 'image/png', 'application/pdf'],
            );
            try {
                DB::transaction(function () use ($reimbursement, $tenantId, $id, $path): void {
                    $reimbursement->delete();
                    $this->privateMedia->deletePrivate(
                        $tenantId,
                        AttendanceReimbursementMediaService::REIMBURSEMENT_CATEGORY,
                        $id,
                        'attachment',
                        $path,
                    );
                });
            } catch (\Throwable $failure) {
                if (! Storage::disk('local')->exists($path)) {
                    $this->privateMedia->restorePrivateExact(
                        $tenantId,
                        AttendanceReimbursementMediaService::REIMBURSEMENT_CATEGORY,
                        $id,
                        'attachment',
                        $path,
                        $bytes,
                        $metadata,
                        ['image/jpeg', 'image/png', 'application/pdf'],
                    );
                }
                throw $failure;
            }

            return;
        }

        $this->privateMedia->assertLegacyPath($path, ['reimbursements/']);
        $bytes = Storage::disk('public')->exists($path) ? Storage::disk('public')->get($path) : null;
        if ($bytes !== null) {
            $this->privateMedia->inspectBytes($bytes, ['image/jpeg', 'image/png', 'application/pdf']);
        }
        try {
            DB::transaction(function () use ($reimbursement, $path, $bytes): void {
                $reimbursement->delete();
                if ($bytes !== null && (! Storage::disk('public')->delete($path)
                    || Storage::disk('public')->exists($path))) {
                    throw new \RuntimeException('Legacy reimbursement media deletion failed.');
                }
            });
        } catch (\Throwable $failure) {
            if ($bytes !== null && ! Storage::disk('public')->exists($path)) {
                if (! Storage::disk('public')->put($path, $bytes)
                    || ! hash_equals(hash('sha256', $bytes), hash('sha256', Storage::disk('public')->get($path)))) {
                    throw new \RuntimeException('Cancellation failed and media compensation was incomplete.', 0, $failure);
                }
            }
            throw $failure;
        }
    }

    public function listForUser(User $user, int $perPage = 10): LengthAwarePaginator
    {
        return Reimbursement::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->with('category')
            ->orderByDesc('date')
            ->paginate($perPage);
    }

    public function categoriesForUser(User $user): Collection
    {
        return ReimbursementCategory::withoutGlobalScopes()
            ->where('company_id', $user->company_id)
            ->where('is_active', true)
            ->get(['id', 'name', 'limit_amount']);
    }

    // ── Admin ─────────────────────────────────────────────────────────────────

    public function listForAdmin(User $admin, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Reimbursement::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->with(['user.division', 'category', 'hrApprovedBy', 'settledBy'])
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
        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
        if (! empty($filters['search'])) {
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%')
            );
        }

        return $query->paginate($perPage);
    }

    public function pendingCount(int $companyId): int
    {
        return Reimbursement::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->count();
    }

    public function summaryForAdmin(User $admin, array $filters = []): array
    {
        $query = Reimbursement::withoutGlobalScopes()
            ->where('company_id', $admin->company_id);

        if (! empty($filters['month'])) {
            $d = Carbon::parse($filters['month'].'-01');
            $query->whereYear('date', $d->year)->whereMonth('date', $d->month);
        }
        if (! empty($filters['division'])) {
            $query->whereHas('user', fn ($q) => $q->where('division_id', $filters['division']));
        }
        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
        if (! empty($filters['search'])) {
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%')
            );
        }

        $reimbursements = $query->get(['status', 'total_amount', 'approved_amount']);

        return [
            'total_requests' => $reimbursements->count(),
            'requested_total' => (int) $reimbursements->sum('total_amount'),
            'approved_total' => (int) $reimbursements->sum(fn (Reimbursement $item) => (int) ($item->approved_amount ?? 0)),
            'average_request' => $reimbursements->count() > 0
                ? (int) round($reimbursements->avg('total_amount'))
                : 0,
            'status_counts' => [
                'pending' => $reimbursements->where('status', 'pending')->count(),
                'approved' => $reimbursements->where('status', 'approved')->count(),
                'rejected' => $reimbursements->where('status', 'rejected')->count(),
            ],
        ];
    }

    /**
     * Step 2: HR/Manager approve reimbursement, lalu diteruskan ke Finance utk settle.
     * Status: pending → hr_approved. Mengisi hr_approved_* fields.
     * Backward compat: approved_amount + approved_by/approved_at lama tetap diisi
     * supaya report/UI lama tidak kosong.
     */
    public function approve(User $admin, int $id, ?int $approvedAmount = null, ?string $notes = null): Reimbursement
    {
        $reimbursement = $this->approvalQuery($admin)->with('user')->findOrFail($id);
        $this->ensurePending($reimbursement->status);
        $finalAmount = $approvedAmount ?? $reimbursement->total_amount;
        $now = now();

        $reimbursement->update([
            'status' => 'hr_approved',
            'notes' => $notes ?: $reimbursement->notes,
            'hr_approved_by' => $admin->id,
            'hr_approved_at' => $now,
            'hr_notes' => $notes,
            // Backward-compat: tetap simpan approved_* untuk report lama
            'approved_by' => $admin->id,
            'approved_at' => $now,
            'approved_amount' => $finalAmount,
        ]);

        app(AuditLogService::class)->log(
            'reimbursement.hr_approved',
            $admin,
            'reimbursement',
            $reimbursement->id,
            [
                'user_id' => $reimbursement->user_id,
                'status' => 'hr_approved',
                'approved_amount' => $finalAmount,
                'notes' => $notes,
            ]
        );

        if ($reimbursement->user) {
            app(NotificationDispatcher::class)->dispatch('reimburse_approved', $reimbursement->user, [
                'amount' => number_format((float) $finalAmount, 0, ',', '.'),
                'next_step' => 'Menunggu pencairan oleh Finance.',
            ]);
        }

        return $reimbursement->fresh();
    }

    /**
     * Step 3: Finance settle the reimbursement - record transfer + upload bukti.
     * Status: hr_approved → settled. Auto-generate kwitansi PDF. Notif karyawan.
     */
    public function settle(User $admin, int $id, UploadedFile $proof, ?string $reference = null): Reimbursement
    {
        $target = $this->approvalQuery($admin)->findOrFail($id);
        $tenantId = (int) ($target->company_id ?: $admin->company_id);
        $proofAsset = null;
        $pdfAsset = null;

        try {
            $proofAsset = $this->privateMedia->storeUploaded(
                $tenantId,
                AttendanceReimbursementMediaService::REIMBURSEMENT_CATEGORY,
                $id,
                'payment-proof',
                $proof,
                ['image/jpeg', 'image/png', 'application/pdf'],
            );

            $fresh = DB::transaction(function () use (
                $admin,
                $id,
                $reference,
                $tenantId,
                $proofAsset,
                &$pdfAsset,
            ): Reimbursement {
                $reimbursement = $this->approvalQuery($admin)
                    ->with('user.division', 'category')
                    ->lockForUpdate()
                    ->findOrFail($id);

                if (! in_array($reimbursement->status, ['hr_approved', 'approved'], true)) {
                    throw ValidationException::withMessages([
                        'status' => "Reimburse #{$id} belum di-approve HR atau sudah di-settle.",
                    ]);
                }

                if ($reimbursement->journal_id !== null || $reimbursement->receipt_number !== null) {
                    throw ValidationException::withMessages([
                        'journal' => "Reimburse #{$id} memiliki jejak settlement yang belum konsisten dan harus direkonsiliasi.",
                    ]);
                }

                $receiptNumber = $this->generateReceiptNumber((int) $reimbursement->company_id);
                // Make the receipt reference available to the journal before the
                // reimbursement row is persisted; the outer transaction owns both.
                $reimbursement->setAttribute('receipt_number', $receiptNumber);

                // Auto-create and post the journal as a hard settlement gate.
                $journalId = $this->createSettlementJournal($admin, $reimbursement, $reference);

                $reimbursement->update([
                    'status' => 'settled',
                    'settled_by' => $admin->id,
                    'settled_at' => now(),
                    'payment_reference' => $reference,
                    'receipt_number' => $receiptNumber,
                    'journal_id' => $journalId,
                    ...$this->mediaAttributes('payment_proof', $proofAsset, 'payment_proof_path'),
                ]);

                // PDF storage is not transactional. Its metadata is captured so the
                // outer catch removes it if any later database write rolls back.
                $pdfAsset = $this->generateReceiptPdf(
                    $reimbursement->fresh()->load('user.division', 'category', 'hrApprovedBy', 'settledBy'),
                    $tenantId,
                );
                $reimbursement->update($this->mediaAttributes('receipt_pdf', $pdfAsset, 'receipt_pdf_path'));

                app(AuditLogService::class)->log(
                    'reimbursement.settled',
                    $admin,
                    'reimbursement',
                    $reimbursement->id,
                    [
                        'user_id' => $reimbursement->user_id,
                        'amount' => $reimbursement->approved_amount,
                        'receipt_number' => $receiptNumber,
                        'reference' => $reference,
                        'journal_id' => $journalId,
                    ]
                );

                return $reimbursement->fresh()->load('user.division', 'category');
            });
        } catch (\Throwable $e) {
            if ($proofAsset !== null) {
                $this->privateMedia->deletePrivate(
                    $tenantId,
                    AttendanceReimbursementMediaService::REIMBURSEMENT_CATEGORY,
                    $id,
                    'payment-proof',
                    $proofAsset['path'],
                );
            }
            if ($pdfAsset !== null) {
                $this->privateMedia->deletePrivate(
                    $tenantId,
                    AttendanceReimbursementMediaService::REIMBURSEMENT_CATEGORY,
                    $id,
                    'receipt',
                    $pdfAsset['path'],
                );
            }

            Log::error('Reimburse settle rolled back', [
                'reimbursement_id' => $id,
                'exception_type' => $e::class,
                'reason' => $e instanceof PrivateAssetIntegrityException
                    ? $e->reason
                    : 'settlement_failed',
            ]);

            if ($e instanceof ValidationException) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'settlement' => 'Pencairan dibatalkan karena settlement, jurnal, atau kwitansi tidak dapat diselesaikan secara atomik.',
            ]);
        }

        if ($fresh->user) {
            try {
                app(NotificationDispatcher::class)->dispatch('reimburse_settled', $fresh->user, [
                    'amount' => number_format((float) $fresh->approved_amount, 0, ',', '.'),
                    'receipt_number' => $fresh->receipt_number,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Reimburse settled notification failed after commit', [
                    'reimbursement_id' => $fresh->id,
                    'exception_type' => $e::class,
                    'reason' => 'notification_failed',
                ]);
            }
        }

        return $fresh;
    }

    private function generateReceiptNumber(int $companyId): string
    {
        $year = now()->format('Y');
        $month = now()->format('m');
        $prefix = "KWT-{$year}/{$month}/";

        // Company row lock serializes receipt generation even when the company
        // has no previous receipts (where a range query alone cannot lock a row).
        DB::table('companies')->where('id', $companyId)->lockForUpdate()->value('id');

        $last = Reimbursement::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('receipt_number', 'like', $prefix.'%')
            ->orderByDesc('receipt_number')
            ->lockForUpdate()
            ->value('receipt_number');
        $seq = 1;
        if ($last) {
            $suffix = substr($last, strlen($prefix));
            $seq = ((int) $suffix) + 1;
        }

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Auto-create posted Journal Entry untuk reimburse yang baru saja di-settle.
     * Resolusi akun:
     *   - DR (debit) = expense account dari category.expense_account_id, atau
     *     auto-create kalau belum ada (naming: "Beban Reimburse - {Category}")
     *   - CR (credit) = 1110 Kas (default kalau payment_reference contains "kas")
     *     atau 1120 Bank (default kalau ada reference transfer)
     * Return: ULID journal_id. Kegagalan adalah hard gate settlement.
     */
    private function createSettlementJournal(User $admin, Reimbursement $reimbursement, ?string $reference): string
    {
        $companyId = (int) $reimbursement->company_id;
        $amount = (int) ($reimbursement->approved_amount ?? $reimbursement->total_amount);
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'journal' => 'Nominal reimbursement harus lebih dari nol.',
            ]);
        }

        // Resolve expense account untuk kategori reimburse ini
        $expenseAccountId = $this->resolveExpenseAccount($companyId, $reimbursement->category);

        // Resolve cash/bank account - kalau ada reference transfer = Bank, kalau tidak = Kas
        $cashAccountCode = $reference ? '1120' : '1110';
        $cashAccount = Account::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('code', $cashAccountCode)
            ->first();
        if (! $cashAccount) {
            throw ValidationException::withMessages([
                'journal' => "Akun {$cashAccountCode} untuk pencairan reimbursement belum dikonfigurasi.",
            ]);
        }

        $description = "Reimburse {$reimbursement->event} - ".($reimbursement->user?->name ?? 'karyawan');
        if ($reference) {
            $description .= " (ref: {$reference})";
        }

        return DB::transaction(function () use (
            $admin, $reimbursement, $description, $expenseAccountId, $cashAccount, $amount
        ): string {
            $journalService = app(JournalService::class);
            $journal = $journalService->create($admin, [
                'date' => now()->toDateString(),
                'description' => $description,
                'reference' => $reimbursement->receipt_number ?? "REIMB-{$reimbursement->id}",
                'source_type' => 'reimbursement',
                'source_id' => (string) $reimbursement->id,
            ], [
                [
                    'account_id' => $expenseAccountId,
                    'debit' => $amount,
                    'credit' => 0,
                    'memo' => "Beban reimburse - {$reimbursement->event}",
                ],
                [
                    'account_id' => $cashAccount->id,
                    'debit' => 0,
                    'credit' => $amount,
                    'memo' => "Pencairan reimburse {$reimbursement->receipt_number}",
                ],
            ]);
            $journalService->post($admin, $journal);

            return (string) $journal->id;
        });
    }

    /**
     * Resolve atau auto-create expense account untuk kategori reimburse.
     * Idempoten: kalau sudah pernah di-resolve untuk kategori ini, langsung return.
     */
    private function resolveExpenseAccount(int $companyId, ?ReimbursementCategory $category): int
    {
        if ($category && $category->expense_account_id) {
            return (int) $category->expense_account_id;
        }

        // Fallback: cari akun yang sudah ada berdasarkan nama kategori (case-insensitive).
        // Mapping heuristic untuk kategori common Indonesia.
        $categoryName = $category?->name ?? 'Lain-lain';
        $heuristicCode = match (true) {
            str_contains(strtolower($categoryName), 'transport') => '5270',  // Transportasi & Bensin
            str_contains(strtolower($categoryName), 'bensin') => '5270',
            str_contains(strtolower($categoryName), 'makan') => '5260',  // Perlengkapan kantor (mendekati)
            str_contains(strtolower($categoryName), 'konsumsi') => '5260',
            str_contains(strtolower($categoryName), 'pemasaran') => '5280',
            str_contains(strtolower($categoryName), 'marketing') => '5280',
            str_contains(strtolower($categoryName), 'sewa') => '5250',
            str_contains(strtolower($categoryName), 'internet') => '5240',
            str_contains(strtolower($categoryName), 'listrik') => '5230',
            default => null,
        };

        if ($heuristicCode) {
            $acc = Account::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('code', $heuristicCode)
                ->first();
            if ($acc) {
                if ($category) {
                    $category->update(['expense_account_id' => $acc->id]);
                }

                return (int) $acc->id;
            }
        }

        // Auto-create akun beban baru di CoA dengan code 5xx tersedia berikutnya
        // di range 5290-5299 (sub-account dari "Beban Operasional" 5200).
        $parent = Account::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('code', '5200')
            ->first();
        $lastCustom = Account::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('code', 'like', '529%')
            ->orderByDesc('code')
            ->value('code');
        $nextNum = $lastCustom ? ((int) substr($lastCustom, -1)) + 1 : 1;
        $code = '529'.$nextNum;
        // Kalau melewati 5299, fallback ke 5330 Lain-lain
        $accountName = "Beban Reimburse - {$categoryName}";
        if ((int) $nextNum > 9) {
            $acc = Account::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('code', '5330')
                ->first();
            if ($acc) {
                if ($category) {
                    $category->update(['expense_account_id' => $acc->id]);
                }

                return (int) $acc->id;
            }
        }

        $acc = Account::create([
            'company_id' => $companyId,
            'code' => $code,
            'name' => $accountName,
            'type' => 'expense',
            'normal_balance' => 'debit',
            'parent_id' => $parent?->id,
            'is_header' => false,
            'is_active' => true,
            'description' => "Auto-generated dari kategori reimburse '{$categoryName}'",
        ]);

        if ($category) {
            $category->update(['expense_account_id' => $acc->id]);
        }

        return (int) $acc->id;
    }

    /** @return array{path:string,sha256:string,size:int,mime:string,width:?int,height:?int} */
    private function generateReceiptPdf(Reimbursement $reimbursement, int $tenantId): array
    {
        $pdf = Pdf::loadView('pdf.reimbursement-receipt', [
            'r' => $reimbursement,
            'user' => $reimbursement->user,
            'category' => $reimbursement->category,
            'hrApprover' => $reimbursement->hrApprovedBy,
            'finance' => $reimbursement->settledBy,
            'company' => Company::find($reimbursement->company_id),
        ])->setPaper('a5', 'portrait');

        return $this->privateMedia->storeBytes(
            $tenantId,
            AttendanceReimbursementMediaService::REIMBURSEMENT_CATEGORY,
            $reimbursement->id,
            'receipt',
            $pdf->output(),
            ['application/pdf'],
        );
    }

    public function reject(User $admin, int $id, ?string $notes = null): Reimbursement
    {
        $reimbursement = $this->approvalQuery($admin)->with('user')->findOrFail($id);
        $this->ensurePending($reimbursement->status);
        $reimbursement->update([
            'status' => 'rejected',
            'notes' => $notes ?: null,
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);
        app(AuditLogService::class)->log(
            'reimbursement.rejected',
            $admin,
            'reimbursement',
            $reimbursement->id,
            [
                'user_id' => $reimbursement->user_id,
                'status' => 'rejected',
                'notes' => $notes,
            ]
        );
        if ($reimbursement->user) {
            app(NotificationDispatcher::class)->dispatch('reimburse_rejected', $reimbursement->user, [
                'rejection_reason' => $notes ?? 'Tidak disebutkan',
            ]);
        }

        return $reimbursement->fresh();
    }

    public function detail(User $admin, int $id): Reimbursement
    {
        return $this->approvalQuery($admin)
            ->with(['user.division', 'category', 'approvedBy'])
            ->findOrFail($id);
    }

    private function approvalQuery(User $admin)
    {
        return Reimbursement::withoutGlobalScopes()->where(function ($query) use ($admin) {
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
            throw ValidationException::withMessages(['status' => 'Reimbursement hanya dapat diproses saat berstatus pending.']);
        }
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

    /**
     * Penghapusan oleh Finance.
     *
     * Sengaja menolak baris yang sudah menyentuh pembukuan.  Reimbursement yang
     * sudah dicairkan punya journal_id; menghapus barisnya akan meninggalkan
     * jurnal yatim dan merusak integritas akuntansi.  Pembatalan transaksi yang
     * sudah dijurnal harus lewat jurnal balik, bukan DELETE.
     */
    public function adminDelete(User $finance, int $id, ?string $reason = null): void
    {
        $reimbursement = Reimbursement::withoutGlobalScopes()
            ->where('company_id', $finance->company_id)
            ->findOrFail($id);

        if ($reimbursement->journal_id) {
            throw ValidationException::withMessages([
                'id' => __('Pengajuan ini sudah masuk jurnal keuangan dan tidak bisa dihapus. Gunakan jurnal balik untuk membatalkannya.'),
            ]);
        }

        if ($reimbursement->status === 'settled') {
            throw ValidationException::withMessages([
                'id' => __('Pengajuan yang sudah dicairkan tidak bisa dihapus.'),
            ]);
        }

        $snapshot = [
            'user_id' => $reimbursement->user_id,
            'status' => $reimbursement->status,
            'date' => (string) $reimbursement->date,
            'title' => $reimbursement->title,
            'category_id' => $reimbursement->category_id,
            'total_amount' => $reimbursement->total_amount,
            'approved_amount' => $reimbursement->approved_amount,
            'reason' => $reason,
        ];

        DB::transaction(function () use ($reimbursement) {
            $reimbursement->delete();
        });

        // Dicatat setelah commit: baris sudah hilang, jadi snapshot di atas adalah
        // satu-satunya jejak isinya.
        app(AuditLogService::class)->log(
            'reimbursement.deleted',
            $finance,
            'reimbursement',
            $id,
            $snapshot
        );
    }

    private function mediaMetadata(Reimbursement $reimbursement, string $prefix): array
    {
        return [
            'sha256' => $reimbursement->getAttribute($prefix.'_sha256'),
            'size' => $reimbursement->getAttribute($prefix.'_size_bytes'),
            'mime' => $reimbursement->getAttribute($prefix.'_mime_type'),
            'width' => $reimbursement->getAttribute($prefix.'_width'),
            'height' => $reimbursement->getAttribute($prefix.'_height'),
        ];
    }
}
