<?php

namespace App\Keuangan\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    use HasUlids;

    protected $table = 'fin_invoice_lines';

    protected $fillable = [
        'invoice_id', 'item_code', 'description', 'quantity', 'unit',
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
