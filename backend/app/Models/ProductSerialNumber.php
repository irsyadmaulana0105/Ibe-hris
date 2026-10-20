<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSerialNumber extends Model
{
    use HasUlids;
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'product_id',
        'serial_number',
        'product_code',
        'product_name',
        'product_variant',
        'spec_values',
        'customer_name',
        'customer_email',
        'customer_phone',
        'issued_at',
        'warranty_until',
        'status',
        'asset_status',
        'asset_location',
        'purchase_value',
        'sale_value',
        'manufactured_at',
        'fin_customer_id',
        'crm_deal_id',
        'notes',
        'generated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'issued_at'       => 'date',
            'warranty_until'  => 'date',
            'manufactured_at' => 'date',
            'spec_values'     => 'array',
            'purchase_value'  => 'decimal:2',
            'sale_value'      => 'decimal:2',
        ];
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
