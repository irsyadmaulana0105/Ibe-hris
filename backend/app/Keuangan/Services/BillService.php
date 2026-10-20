<?php

namespace App\Keuangan\Services;

use App\Keuangan\Models\Account;
use App\Keuangan\Models\Bill;
use App\Keuangan\Models\BillLine;
use App\Keuangan\Models\Journal;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bill / Hutang ke Supplier (Accounts Payable). Mirror dari InvoiceService:
 *
 *   received → DR Beban/Aset (per-line expense_account_id), DR PPN Masukan (1150),
 *              CR Hutang Usaha (2110) - total
 *   paid     → DR Hutang Usaha (2110), CR Kas/Bank
 */
class BillService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly JournalService $journalService,
    ) {}

    public function generateNumber(int $companyId, string $date): string
    {
        $year = substr($date, 0, 4);
        $month = substr($date, 5, 2);
        $prefix = "BILL-{$year}/{$month}/";

        return DB::transaction(function () use ($companyId, $prefix) {
            $last = Bill::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('number', 'like', $prefix . '%')
                ->orderByDesc('number')
                ->lockForUpdate()
                ->value('number');
            $seq = 1;
            if ($last) {
                $suffix = substr($last, strlen($prefix));
                $seq = ((int) $suffix) + 1;
            }
            return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        });
    }

    public function create(User $admin, array $data, array $lines): Bill
    {
        return DB::transaction(function () use ($admin, $data, $lines) {
            $bill = Bill::create([
                'company_id'        => $admin->company_id,
                'number'            => $this->generateNumber((int) $admin->company_id, $data['date']),
                'date'              => $data['date'],
                'due_date'          => $data['due_date'] ?? null,
                'supplier_id'       => $data['supplier_id'] ?? null,
                'supplier_name'     => $data['supplier_name'],
                'supplier_invoice_no'=> $data['supplier_invoice_no'] ?? null,
                'description'       => $data['description'] ?? null,
                'tax_rate'          => $data['tax_rate'] ?? 0,
                'status'            => 'draft',
                'created_by'        => $admin->id,
            ]);

            $subtotal = 0.0;
            foreach ($lines as $i => $l) {
                $qty = (float) ($l['quantity'] ?? 1);
                $price = (float) ($l['unit_price'] ?? 0);
                $amount = round($qty * $price, 2);
                BillLine::create([
                    'id'                => (string) Str::ulid(),
                    'bill_id'           => $bill->id,
                    'expense_account_id'=> (int) $l['expense_account_id'],
                    'description'       => $l['description'],
                    'quantity'          => $qty,
                    'unit'              => $l['unit'] ?? null,
                    'unit_price'        => $price,
                    'amount'            => $amount,
                    'line_order'        => $i,
                ]);
                $subtotal += $amount;
            }

            $taxAmount = round($subtotal * ((float) ($data['tax_rate'] ?? 0)) / 100, 2);
            $total = round($subtotal + $taxAmount, 2);

            $bill->update([
                'subtotal'   => $subtotal,
                'tax_amount' => $taxAmount,
                'total'      => $total,
            ]);

            $this->audit->log('finance.bill.created', $admin, 'fin_bill', $bill->id,
                ['number' => $bill->number, 'total' => $total, 'supplier' => $bill->supplier_name]);

            return $bill->fresh()->load('lines');
        });
    }

    public function receive(User $admin, Bill $bill): Bill
    {
        if ($bill->status !== 'draft') {
            throw ValidationException::withMessages(['status' => "Bill {$bill->number} bukan draft."]);
        }

        return DB::transaction(function () use ($admin, $bill) {
            $lines = [];
            // Per-line debit ke expense account
            foreach ($bill->lines as $bl) {
                $lines[] = [
                    'account_id' => $bl->expense_account_id,
                    'debit'      => (float) $bl->amount,
                    'credit'     => 0,
                    'memo'       => $bl->description,
                ];
            }
            // PPN Masukan (asset, debit) kalau ada
            if ((float) $bill->tax_amount > 0) {
                $lines[] = [
                    'account_id' => $this->accId($admin, '1150'),  // Biaya Dibayar Dimuka - proxy PPN Masukan kalau belum ada akun khusus
                    'debit'      => (float) $bill->tax_amount,
                    'credit'     => 0,
                    'memo'       => "PPN Masukan {$bill->tax_rate}% atas {$bill->number}",
                ];
            }
            // Credit Hutang Usaha
            $lines[] = [
                'account_id' => $this->accId($admin, '2110'),
                'debit'      => 0,
                'credit'     => (float) $bill->total,
                'memo'       => "Hutang ke {$bill->supplier_name} ({$bill->number})",
            ];

            $journal = $this->journalService->create($admin, [
                'date'        => $bill->date->toDateString(),
                'description' => "Pembelian {$bill->number} dari {$bill->supplier_name}",
                'reference'   => $bill->number,
            ], $lines);
            $this->journalService->post($admin, $journal);

            $bill->update([
                'status'              => 'received',
                'received_journal_id' => $journal->id,
                'received_by'         => $admin->id,
                'received_at'         => now(),
            ]);

            $this->audit->log('finance.bill.received', $admin, 'fin_bill', $bill->id,
                ['number' => $bill->number, 'journal' => $journal->number]);

            return $bill->fresh();
        });
    }

    public function pay(User $admin, Bill $bill, string $paymentAccountCode, float $amount): Bill
    {
        return DB::transaction(function () use ($admin, $bill, $paymentAccountCode, $amount) {
            // Never trust the controller's model snapshot for a money mutation.
            // Serialize payments per bill and validate against the locked state.
            $lockedBill = Bill::withoutGlobalScopes()
                ->where('company_id', $admin->company_id)
                ->lockForUpdate()
                ->findOrFail($bill->getKey());

            if (! in_array($lockedBill->status, ['received', 'partial', 'overdue'], true)) {
                throw ValidationException::withMessages([
                    'status' => "Bill {$lockedBill->number} belum bisa dibayar.",
                ]);
            }

            $totalCents = (int) round((float) $lockedBill->total * 100);
            $paidCents = (int) round((float) $lockedBill->paid_amount * 100);
            $remainingCents = max(0, $totalCents - $paidCents);
            $amountCents = (int) round($amount * 100);
            $remaining = $remainingCents / 100;

            if ($amountCents <= 0 || $amountCents > $remainingCents) {
                throw ValidationException::withMessages([
                    'amount' => 'Jumlah invalid (sisa Rp '.number_format($remaining, 0, ',', '.').')',
                ]);
            }

            $normalizedAmount = $amountCents / 100;
            $lines = [
                ['account_id' => $this->accId($admin, '2110'), 'debit' => $normalizedAmount, 'credit' => 0, 'memo' => "Pelunasan hutang {$lockedBill->supplier_name}"],
                ['account_id' => $this->accId($admin, $paymentAccountCode), 'debit' => 0, 'credit' => $normalizedAmount, 'memo' => "Pembayaran bill {$lockedBill->number}"],
            ];

            $journal = $this->journalService->create($admin, [
                'date'        => now()->toDateString(),
                'description' => "Pembayaran hutang {$lockedBill->number} ke {$lockedBill->supplier_name}",
                'reference'   => $lockedBill->number,
            ], $lines);
            $postedJournal = $this->journalService->post($admin, $journal);
            if ($postedJournal->status !== 'posted') {
                throw ValidationException::withMessages([
                    'journal' => 'Jurnal pembayaran bill gagal mencapai status posted.',
                ]);
            }

            $newPaidCents = $paidCents + $amountCents;
            $newPaid = $newPaidCents / 100;
            $isFullPaid = $newPaidCents === $totalCents;

            $lockedBill->update([
                'paid_amount'         => $newPaid,
                'status'              => $isFullPaid ? 'paid' : 'partial',
                'payment_journal_id'  => $postedJournal->id,
                'paid_by'             => $admin->id,
                'paid_at'             => now(),
                'payment_account_code'=> $paymentAccountCode,
            ]);

            $this->audit->log('finance.bill.paid', $admin, 'fin_bill', $lockedBill->id,
                ['number' => $lockedBill->number, 'amount' => $normalizedAmount, 'journal' => $postedJournal->number]);

            return $lockedBill->fresh();
        });
    }

    private function accId(User $admin, string $code): int
    {
        $acc = Account::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->where('code', $code)
            ->first();
        if (!$acc) {
            throw ValidationException::withMessages(['account' => "Akun {$code} tidak ditemukan."]);
        }
        return $acc->id;
    }
}
