<?php

namespace App\Keuangan\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillLine extends Model
{
    use HasUlids;

    protected $table = 'fin_bill_lines';

    protected $fillable = [
        'bill_id', 'expense_account_id', 'description', 'quantity', 'unit',
        'unit_price', 'amount', 'line_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity'   => 'decimal:2',
            'unit_price' => 'decimal:2',
            'amount'     => 'decimal:2',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }
}
