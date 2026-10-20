<?php

namespace App\Keuangan\Services;

use App\Keuangan\Models\Account;
use App\Keuangan\Models\Customer;
use App\Keuangan\Models\Invoice;
use App\Keuangan\Models\InvoiceLine;
use App\Keuangan\Models\Journal;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sales Invoice / Faktur Penjualan + auto-create Journal saat status change.
 *
 * Status workflow:
 *   draft   → tidak ada journal entry
 *   sent    → auto-create Journal: DR Piutang 1130, CR Penjualan 4110, CR Hutang PPN 2130
 *   paid    → auto-create Journal: DR Kas/Bank, CR Piutang 1130
 *   partial → seperti paid tapi recorded sebagian
 *   cancelled → auto-reverse journal sent (kalau sudah sent)
 */
class InvoiceService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly JournalService $journalService,
    ) {}

    public function generateNumber(int $companyId, string $date): string
    {
        $year = substr($date, 0, 4);
        $month = substr($date, 5, 2);
        $prefix = "INV-{$year}/{$month}/";

        return DB::transaction(function () use ($companyId, $prefix) {
            $last = Invoice::withoutGlobalScopes()
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

    public function create(User $admin, array $data, array $lines): Invoice
    {
        return DB::transaction(function () use ($admin, $data, $lines) {
            $invoice = Invoice::create([
                'company_id'   => $admin->company_id,
                'number'       => $this->generateNumber((int) $admin->company_id, $data['date']),
                'date'         => $data['date'],
                'due_date'     => $data['due_date'] ?? null,
                'customer_id'  => $data['customer_id'] ?? null,
                'customer_name'=> $data['customer_name'],
                'customer_npwp'=> $data['customer_npwp'] ?? null,
                'customer_address' => $data['customer_address'] ?? null,
                'description'  => $data['description'] ?? null,
                'reference'    => $data['reference'] ?? null,
                'tax_rate'     => $data['tax_rate'] ?? 11,
                'discount'     => $data['discount'] ?? 0,
                'status'       => 'draft',
                'created_by'   => $admin->id,
            ]);

            $subtotal = 0.0;
            foreach ($lines as $i => $l) {
                $qty = (float) ($l['quantity'] ?? 1);
                $price = (float) ($l['unit_price'] ?? 0);
                $amount = round($qty * $price, 2);
                InvoiceLine::create([
                    'id'         => (string) Str::ulid(),
                    'invoice_id' => $invoice->id,
                    'item_code'  => $l['item_code'] ?? null,
                    'description'=> $l['description'],
                    'quantity'   => $qty,
                    'unit'       => $l['unit'] ?? null,
                    'unit_price' => $price,
                    'amount'     => $amount,
                    'line_order' => $i,
                ]);
                $subtotal += $amount;
            }

            $discount = (float) ($data['discount'] ?? 0);
            $taxBase = max(0, $subtotal - $discount);
            $taxAmount = round($taxBase * ((float) ($data['tax_rate'] ?? 11)) / 100, 2);
            $total = round($taxBase + $taxAmount, 2);

            $invoice->update([
                'subtotal'   => $subtotal,
                'tax_amount' => $taxAmount,
                'total'      => $total,
            ]);

            $this->audit->log('finance.invoice.created', $admin, 'fin_invoice', $invoice->id,
                ['number' => $invoice->number, 'total' => $total, 'customer' => $invoice->customer_name]);

            return $invoice->fresh()->load('lines');
        });
    }

    /**
     * Mark invoice as "sent" → auto-create journal entry:
     *   DR Piutang Usaha (1130) - total
     *   CR Penjualan Produk (4110) - subtotal - discount
     *   CR Hutang Pajak PPN (2130) - tax_amount
     */
    public function send(User $admin, Invoice $invoice): Invoice
    {
        if ($invoice->status !== 'draft') {
            throw ValidationException::withMessages(['status' => "Invoice {$invoice->number} bukan draft."]);
        }

        return DB::transaction(function () use ($admin, $invoice) {
            $taxBase = (float) $invoice->subtotal - (float) $invoice->discount;
            $lines = [
                ['account_id' => $this->accId($admin, '1130'), 'debit' => $invoice->total, 'credit' => 0, 'memo' => "Faktur {$invoice->number} ke {$invoice->customer_name}"],
                ['account_id' => $this->accId($admin, '4110'), 'debit' => 0, 'credit' => $taxBase, 'memo' => "Penjualan: {$invoice->description}"],
            ];
            if ((float) $invoice->tax_amount > 0) {
                $lines[] = ['account_id' => $this->accId($admin, '2130'), 'debit' => 0, 'credit' => $invoice->tax_amount, 'memo' => "PPN {$invoice->tax_rate}% atas {$invoice->number}"];
            }

            $journal = $this->journalService->create($admin, [
                'date'        => $invoice->date->toDateString(),
                'description' => "Faktur Penjualan {$invoice->number} - {$invoice->customer_name}",
                'reference'   => $invoice->number,
            ], $lines);
            $this->journalService->post($admin, $journal);

            $invoice->update([
                'status'          => 'sent',
                'sent_journal_id' => $journal->id,
                'sent_by'         => $admin->id,
                'sent_at'         => now(),
            ]);

            $this->audit->log('finance.invoice.sent', $admin, 'fin_invoice', $invoice->id,
                ['number' => $invoice->number, 'journal_number' => $journal->number]);

            return $invoice->fresh();
        });
    }

    /**
     * Record payment → auto-create journal:
     *   DR Kas/Bank (per payment_account_code)
     *   CR Piutang Usaha (1130)
     */
    public function pay(User $admin, Invoice $invoice, string $paymentAccountCode, float $amount): Invoice
    {
        if (!in_array($invoice->status, ['sent', 'partial', 'overdue'], true)) {
            throw ValidationException::withMessages(['status' => "Invoice {$invoice->number} belum bisa dibayar (status: {$invoice->status})."]);
        }
        $remaining = (float) $invoice->total - (float) $invoice->paid_amount;
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Jumlah pembayaran harus > 0.']);
        }
        if ($amount > $remaining + 0.01) {
            throw ValidationException::withMessages(['amount' => "Jumlah melebihi sisa Rp " . number_format($remaining, 0, ',', '.')]);
        }

        return DB::transaction(function () use ($admin, $invoice, $paymentAccountCode, $amount, $remaining) {
            $lines = [
                ['account_id' => $this->accId($admin, $paymentAccountCode), 'debit' => $amount, 'credit' => 0, 'memo' => "Pembayaran {$invoice->number}"],
                ['account_id' => $this->accId($admin, '1130'), 'debit' => 0, 'credit' => $amount, 'memo' => "Pelunasan Piutang {$invoice->customer_name}"],
            ];

            $journal = $this->journalService->create($admin, [
                'date'        => now()->toDateString(),
                'description' => "Pembayaran {$invoice->number} - {$invoice->customer_name}",
                'reference'   => $invoice->number,
            ], $lines);
            $this->journalService->post($admin, $journal);

            $newPaid = (float) $invoice->paid_amount + $amount;
            $isFullPaid = abs($newPaid - (float) $invoice->total) < 0.01;

            $invoice->update([
                'paid_amount'         => $newPaid,
                'status'              => $isFullPaid ? 'paid' : 'partial',
                'payment_journal_id'  => $journal->id,
                'paid_by'             => $admin->id,
                'paid_at'             => now(),
                'payment_account_code'=> $paymentAccountCode,
            ]);

            $this->audit->log('finance.invoice.paid', $admin, 'fin_invoice', $invoice->id,
                ['number' => $invoice->number, 'amount' => $amount, 'journal' => $journal->number, 'is_full' => $isFullPaid]);

            return $invoice->fresh();
        });
    }

    public function cancel(User $admin, Invoice $invoice, string $reason): Invoice
    {
        if ($invoice->status === 'cancelled') return $invoice;

        return DB::transaction(function () use ($admin, $invoice, $reason) {
            // Reverse sent journal kalau sudah ada
            if ($invoice->sent_journal_id) {
                $j = Journal::find($invoice->sent_journal_id);
                if ($j && $j->status === 'posted') {
                    $this->journalService->reverse($admin, $j, "Cancellation invoice {$invoice->number}: {$reason}");
                }
            }
            $invoice->update(['status' => 'cancelled']);

            $this->audit->log('finance.invoice.cancelled', $admin, 'fin_invoice', $invoice->id,
                ['number' => $invoice->number, 'reason' => $reason]);

            return $invoice->fresh();
        });
    }

    private function accId(User $admin, string $code): int
    {
        $acc = Account::withoutGlobalScopes()
            ->where('company_id', $admin->company_id)
            ->where('code', $code)
            ->first();
        if (!$acc) {
            throw ValidationException::withMessages(['account' => "Akun {$code} tidak ditemukan. Pastikan Chart of Accounts sudah di-seed."]);
        }
        return $acc->id;
    }
}
