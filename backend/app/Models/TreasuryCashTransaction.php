<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class TreasuryCashTransaction extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'bank_account_id', 'payment_id', 'type', 'amount', 'balance_after', 'transaction_date', 'reference', 'description', 'posted_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'balance_after' => 'decimal:2', 'transaction_date' => 'date'];
    }
}
