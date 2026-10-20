<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockCount extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'count_number', 'warehouse_id', 'count_date',
        'status', 'created_by', 'counted_by', 'approved_by',
        'counting_started_at', 'counting_completed_at', 'posted_at',
        'notes', 'filter_used',
    ];

    protected function casts(): array
    {
        return [
            'count_date'             => 'date',
            'filter_used'            => 'array',
            'counting_started_at'    => 'datetime',
            'counting_completed_at'  => 'datetime',
            'posted_at'              => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouse::class, 'warehouse_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }
}
