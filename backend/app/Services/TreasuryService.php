<?php

namespace App\Services;

use App\Models\TreasuryBankAccount;
use App\Models\TreasuryCashTransaction;
use App\Models\TreasuryPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TreasuryService
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function createBankAccount(User $actor, array $data): TreasuryBankAccount
    {
        $account = TreasuryBankAccount::withoutGlobalScopes()->create($data + [
            'company_id' => $actor->company_id,
            'current_balance' => $data['opening_balance'] ?? 0,
            'created_by' => $actor->id,
        ]);
        $this->audit->log('treasury.bank_account.created', $actor, 'treasury_bank_account', $account->id);
        return $account;
    }

    public function createPayment(User $actor, array $data): TreasuryPayment
    {
        $account = $this->account($actor, (int) $data['bank_account_id']);
        if (! $account->is_active) {
            throw ValidationException::withMessages(['bank_account_id' => 'Rekening bank tidak aktif.']);
        }
        $sequence = TreasuryPayment::withoutGlobalScopes()->where('company_id', $actor->company_id)->count() + 1;
        $payment = TreasuryPayment::withoutGlobalScopes()->create($data + [
            'company_id' => $actor->company_id,
            'reference' => sprintf('PAY-%s-%06d', now()->format('Y'), $sequence),
            'currency' => $account->currency,
            'status' => 'draft',
            'maker_id' => $actor->id,
        ]);
        $this->audit->log('treasury.payment.created', $actor, 'treasury_payment', $payment->id, ['reference' => $payment->reference, 'amount' => $payment->amount]);
        return $payment;
    }

    public function submit(User $actor, int $id): TreasuryPayment
    {
        return $this->transition($actor, $id, 'draft', 'submitted', 'treasury.payment.submitted');
    }

    public function approve(User $actor, int $id): TreasuryPayment
    {
        $payment = $this->payment($actor, $id);
        if ((string) $payment->maker_id === (string) $actor->id) {
            throw ValidationException::withMessages(['payment' => 'Pembuat pembayaran tidak boleh menyetujui transaksi yang sama.']);
        }
        if ($payment->status !== 'submitted') {
            throw ValidationException::withMessages(['status' => 'Hanya pembayaran submitted yang dapat disetujui.']);
        }
        $payment->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);
        $this->audit->log('treasury.payment.approved', $actor, 'treasury_payment', $payment->id);
        return $payment->fresh();
    }

    public function reject(User $actor, int $id, string $reason): TreasuryPayment
    {
        $payment = $this->payment($actor, $id);
        if ((string) $payment->maker_id === (string) $actor->id) {
            throw ValidationException::withMessages(['payment' => 'Pembuat pembayaran tidak boleh memeriksa transaksi yang sama.']);
        }
        if ($payment->status !== 'submitted') {
            throw ValidationException::withMessages(['status' => 'Hanya pembayaran submitted yang dapat ditolak.']);
        }
        $payment->update(['status' => 'rejected', 'approved_by' => $actor->id, 'approved_at' => now(), 'rejection_reason' => $reason]);
        $this->audit->log('treasury.payment.rejected', $actor, 'treasury_payment', $payment->id, ['reason' => $reason]);
        return $payment->fresh();
    }

    public function execute(User $actor, int $id, string $bankReference): TreasuryPayment
    {
        return DB::transaction(function () use ($actor, $id, $bankReference) {
            $payment = TreasuryPayment::withoutGlobalScopes()->where('company_id', $actor->company_id)->lockForUpdate()->findOrFail($id);
            if ($payment->status !== 'approved') {
                throw ValidationException::withMessages(['status' => 'Hanya pembayaran approved yang dapat dieksekusi.']);
            }
            if ((string) $payment->approved_by === (string) $actor->id) {
                throw ValidationException::withMessages(['payment' => 'Penyetuju tidak boleh mengeksekusi pembayaran yang sama.']);
            }
            $account = TreasuryBankAccount::withoutGlobalScopes()->where('company_id', $actor->company_id)->lockForUpdate()->findOrFail($payment->bank_account_id);
            $signedAmount = $payment->direction === 'outbound' ? -((float) $payment->amount) : (float) $payment->amount;
            $balanceAfter = (float) $account->current_balance + $signedAmount;
            if ($balanceAfter < 0) {
                throw ValidationException::withMessages(['amount' => 'Saldo rekening tidak mencukupi.']);
            }
            $account->update(['current_balance' => $balanceAfter]);
            $payment->update(['status' => 'executed', 'executed_by' => $actor->id, 'executed_at' => now(), 'bank_reference' => $bankReference]);
            TreasuryCashTransaction::withoutGlobalScopes()->create([
                'company_id' => $actor->company_id, 'bank_account_id' => $account->id, 'payment_id' => $payment->id,
                'type' => $payment->direction === 'outbound' ? 'debit' : 'credit', 'amount' => $payment->amount,
                'balance_after' => $balanceAfter, 'transaction_date' => now()->toDateString(),
                'reference' => $bankReference, 'description' => $payment->description, 'posted_by' => $actor->id,
            ]);
            $this->audit->log('treasury.payment.executed', $actor, 'treasury_payment', $payment->id, ['bank_reference' => $bankReference, 'balance_after' => $balanceAfter]);
            return $payment->fresh();
        });
    }

    private function transition(User $actor, int $id, string $from, string $to, string $audit): TreasuryPayment
    {
        $payment = $this->payment($actor, $id);
        if ($payment->status !== $from) {
            throw ValidationException::withMessages(['status' => "Hanya pembayaran {$from} yang dapat diproses."]);
        }
        $payment->update(['status' => $to]);
        $this->audit->log($audit, $actor, 'treasury_payment', $payment->id);
        return $payment->fresh();
    }

    private function payment(User $actor, int $id): TreasuryPayment
    {
        return TreasuryPayment::withoutGlobalScopes()->where('company_id', $actor->company_id)->findOrFail($id);
    }

    private function account(User $actor, int $id): TreasuryBankAccount
    {
        return TreasuryBankAccount::withoutGlobalScopes()->where('company_id', $actor->company_id)->findOrFail($id);
    }
}
