<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class TreasuryBankAccount extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'bank_name', 'account_number', 'currency', 'opening_balance', 'current_balance', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['opening_balance' => 'decimal:2', 'current_balance' => 'decimal:2', 'is_active' => 'boolean'];
    }
}
