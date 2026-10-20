<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class TreasuryPayment extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'reference', 'bank_account_id', 'direction', 'counterparty', 'counterparty_account', 'amount', 'currency', 'scheduled_for', 'description', 'status', 'maker_id', 'approved_by', 'approved_at', 'executed_by', 'executed_at', 'bank_reference', 'rejection_reason'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'scheduled_for' => 'date', 'approved_at' => 'datetime', 'executed_at' => 'datetime'];
    }
}
