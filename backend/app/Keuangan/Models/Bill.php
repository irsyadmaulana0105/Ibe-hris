<?php

namespace App\Keuangan\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bill extends Model
{
    use HasUlids, BelongsToCompany;

    protected $table = 'fin_bills';

    protected $fillable = [
        'company_id', 'number', 'date', 'due_date',
        'supplier_id', 'supplier_name', 'supplier_invoice_no',
        'description',
        'subtotal', 'tax_rate', 'tax_amount', 'total', 'paid_amount',
        'status', 'received_journal_id', 'payment_journal_id',
        'created_by', 'received_by', 'received_at', 'paid_by', 'paid_at', 'payment_account_code',
    ];

    protected function casts(): array
    {
        return [
            'date'        => 'date',
            'due_date'    => 'date',
            'received_at' => 'datetime',
            'paid_at'     => 'datetime',
            'subtotal'    => 'decimal:2',
            'tax_rate'    => 'decimal:2',
            'tax_amount'  => 'decimal:2',
            'total'       => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class)->orderBy('line_order');
    }
}
