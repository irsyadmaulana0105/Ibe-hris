<?php

namespace App\Keuangan\Services;

use App\Keuangan\Models\Account;
use App\Keuangan\Models\Journal;
use App\Keuangan\Models\JournalLine;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Layanan jurnal akuntansi double-entry.
 *
 * Aturan ISO:
 *   - Setiap baris jurnal HANYA debit ATAU credit, tidak boleh keduanya
 *   - Total debit == total credit per jurnal (balanced)
 *   - Akun header tidak boleh dipakai di baris (hanya leaf account)
 *   - Posted journal tidak bisa di-edit/delete (audit trail), hanya bisa di-reverse
 */
class JournalService
{
    public function __construct(private readonly AuditLogService $audit) {}

    /** Generate auto-increment journal number per company per bulan. */
    public function generateNumber(int $companyId, string $date): string
    {
        $year = substr($date, 0, 4);
        $month = substr($date, 5, 2);
        $prefix = "JV-{$year}/{$month}/";

        return DB::transaction(function () use ($companyId, $prefix) {
            $last = Journal::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('number', 'like', $prefix . '%')
                ->orderByDesc('number')
                ->lockForUpdate()
                ->first(['number']);
            $seq = 1;
            if ($last) {
                $suffix = substr($last->number, strlen($prefix));
                $seq = ((int) $suffix) + 1;
            }
            return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Create journal dalam status draft + validate lines balance.
     * Lines format: [['account_id' => X, 'debit' => 100, 'credit' => 0, 'memo' => '...'], ...]
     */
    public function create(User $admin, array $data, array $lines): Journal
    {
        return DB::transaction(function () use ($admin, $data, $lines) {
            $this->validateLines($admin, $lines);

            $totalDebit  = collect($lines)->sum(fn ($l) => (float) ($l['debit'] ?? 0));
            $totalCredit = collect($lines)->sum(fn ($l) => (float) ($l['credit'] ?? 0));

            if (abs($totalDebit - $totalCredit) > 0.01) {
                throw ValidationException::withMessages([
                    'lines' => "Jurnal tidak balanced: debit Rp " . number_format($totalDebit, 0, ',', '.')
                             . " ≠ credit Rp " . number_format($totalCredit, 0, ',', '.'),
                ]);
            }

            $journal = Journal::create([
                'company_id'   => $admin->company_id,
                'number'       => $data['number'] ?? $this->generateNumber((int) $admin->company_id, $data['date']),
                'date'         => $data['date'],
                'description'  => $data['description'],
                'reference'    => $data['reference'] ?? null,
                'status'       => 'draft',
                'total_debit'  => $totalDebit,
                'total_credit' => $totalCredit,
                'created_by'   => $admin->id,
            ]);

            foreach ($lines as $i => $l) {
                JournalLine::create([
                    'id'         => (string) Str::ulid(),
                    'journal_id' => $journal->id,
                    'account_id' => (int) $l['account_id'],
                    'debit'      => (float) ($l['debit']  ?? 0),
                    'credit'     => (float) ($l['credit'] ?? 0),
                    'memo'       => $l['memo'] ?? null,
                    'line_order' => $i,
                ]);
            }

            $this->audit->log(
                'finance.journal.created',
                $admin, 'fin_journal', $journal->id,
                ['number' => $journal->number, 'total' => $totalDebit, 'lines_count' => count($lines)],
            );

            return $journal->load('lines.account');
        });
    }

    /** Post jurnal - final, masuk Buku Besar, tidak bisa edit lagi. */
    public function post(User $admin, Journal $journal): Journal
    {
        if ($journal->status !== 'draft') {
            throw ValidationException::withMessages(['status' => "Jurnal {$journal->number} bukan draft (status: {$journal->status})."]);
        }

        $journal->update([
            'status'    => 'posted',
            'posted_by' => $admin->id,
            'posted_at' => now(),
        ]);

        $this->audit->log(
            'finance.journal.posted',
            $admin, 'fin_journal', $journal->id,
            ['number' => $journal->number, 'total' => (float) $journal->total_debit],
        );

        return $journal->fresh()->load('lines.account');
    }

    /** Reverse posted journal - create entry kebalikan tanggal hari ini. */
    public function reverse(User $admin, Journal $journal, string $reason): Journal
    {
        if ($journal->status !== 'posted') {
            throw ValidationException::withMessages(['status' => "Hanya jurnal posted yang bisa di-reverse."]);
        }

        $reverseLines = $journal->lines->map(fn ($l) => [
            'account_id' => $l->account_id,
            'debit'      => (float) $l->credit,  // swap!
            'credit'     => (float) $l->debit,
            'memo'       => "REVERSAL: " . ($l->memo ?? ''),
        ])->all();

        $reverseJournal = $this->create($admin, [
            'date'        => now()->toDateString(),
            'description' => "REVERSAL of {$journal->number}: {$reason}",
            'reference'   => $journal->number,
        ], $reverseLines);
        $this->post($admin, $reverseJournal);

        $journal->update([
            'status'         => 'reversed',
            'reversed_by'    => $admin->id,
            'reversed_at'    => now(),
            'reverse_reason' => $reason,
        ]);

        $this->audit->log(
            'finance.journal.reversed',
            $admin, 'fin_journal', $journal->id,
            ['number' => $journal->number, 'reversal_number' => $reverseJournal->number, 'reason' => $reason],
        );

        return $journal->fresh()->load('lines.account');
    }

    /** Delete draft journal (posted gak bisa). */
    public function delete(User $admin, Journal $journal): void
    {
        if ($journal->status !== 'draft') {
            throw ValidationException::withMessages(['status' => "Hanya draft yang bisa dihapus. Untuk posted, pakai reverse."]);
        }

        $this->audit->log(
            'finance.journal.deleted',
            $admin, 'fin_journal', $journal->id,
            ['number' => $journal->number],
        );

        $journal->lines()->delete();
        $journal->delete();
    }

    private function validateLines(User $admin, array $lines): void
    {
        if (count($lines) < 2) {
            throw ValidationException::withMessages(['lines' => 'Minimal 2 baris jurnal (debit + credit).']);
        }

        $accountIds = collect($lines)->pluck('account_id')->filter()->unique()->all();
        $accounts = Account::query()->withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->whereIn('id', $accountIds)
            ->get()->keyBy('id');

        foreach ($lines as $i => $l) {
            $accId = (int) ($l['account_id'] ?? 0);
            $debit  = (float) ($l['debit']  ?? 0);
            $credit = (float) ($l['credit'] ?? 0);

            if (!isset($accounts[$accId])) {
                throw ValidationException::withMessages(["lines.{$i}.account_id" => 'Akun tidak ditemukan.']);
            }
            if ($accounts[$accId]->is_header) {
                throw ValidationException::withMessages(["lines.{$i}.account_id" => 'Akun header tidak boleh dipakai di baris jurnal.']);
            }
            if ($debit > 0 && $credit > 0) {
                throw ValidationException::withMessages(["lines.{$i}" => 'Baris hanya boleh debit ATAU credit, bukan keduanya.']);
            }
            if ($debit == 0 && $credit == 0) {
                throw ValidationException::withMessages(["lines.{$i}" => 'Baris harus punya nominal debit atau credit > 0.']);
            }
        }
    }
}
