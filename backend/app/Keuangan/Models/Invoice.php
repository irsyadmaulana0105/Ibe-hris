<?php

namespace App\Keuangan\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasUlids, BelongsToCompany;

    protected $table = 'fin_invoices';

    protected $fillable = [
        'company_id', 'number', 'date', 'due_date',
        'customer_id', 'customer_name', 'customer_npwp', 'customer_address',
        'description', 'reference',
        'subtotal', 'discount', 'tax_rate', 'tax_amount', 'total', 'paid_amount',
        'status', 'sent_journal_id', 'payment_journal_id',
        'created_by', 'sent_by', 'sent_at', 'paid_by', 'paid_at', 'payment_account_code',
    ];

    protected function casts(): array
    {
        return [
            'date'        => 'date',
            'due_date'    => 'date',
            'sent_at'     => 'datetime',
            'paid_at'     => 'datetime',
            'subtotal'    => 'decimal:2',
            'discount'    => 'decimal:2',
            'tax_rate'    => 'decimal:2',
            'tax_amount'  => 'decimal:2',
            'total'       => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('line_order');
    }
}
