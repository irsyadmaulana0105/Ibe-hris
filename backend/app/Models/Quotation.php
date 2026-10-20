<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quotation extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id', 'quote_number', 'public_token', 'title', 'project_name',
        'customer_id', 'customer_name', 'customer_address',
        'customer_pic_name', 'customer_pic_title',
        'quote_date', 'valid_until', 'crm_deal_id',
        'subtotal', 'discount', 'discount_pct', 'after_discount',
        'tax_rate', 'tax_amount', 'total',
        'payment_terms', 'delivery_terms', 'warranty_terms', 'notes', 'preamble',
        'status', 'prepared_by',
        'sent_at', 'accepted_at', 'rejected_at', 'rejection_reason',
        'customer_responded_at', 'customer_response_note',
        'email_sent_to', 'email_sent_at',
        'fin_invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'quote_date'   => 'date',
            'valid_until'  => 'date',
            'sent_at'      => 'datetime',
            'accepted_at'  => 'datetime',
            'rejected_at'  => 'datetime',
            'customer_responded_at' => 'datetime',
            'email_sent_at'         => 'datetime',
            'subtotal'     => 'decimal:2',
            'discount'     => 'decimal:2',
            'discount_pct' => 'decimal:2',
            'after_discount'=> 'decimal:2',
            'tax_rate'     => 'decimal:2',
            'tax_amount'   => 'decimal:2',
            'total'        => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->valid_until && now()->gt($this->valid_until) && in_array($this->status, ['draft', 'sent'], true);
    }
}
