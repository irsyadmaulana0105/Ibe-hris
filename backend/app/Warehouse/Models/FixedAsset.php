<?php

namespace App\Warehouse\Models;

use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FixedAsset extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id', 'asset_tag', 'name', 'category', 'description',
        'serial_number', 'acquisition_date', 'acquisition_cost', 'currency',
        'exchange_rate', 'cost_in_base', 'salvage_value',
        'useful_life_months', 'depreciation_method',
        'accumulated_depreciation', 'book_value', 'last_depreciation_date',
        'status', 'location', 'assigned_to_user_id', 'supplier_id', 'invoice_no',
        'disposal_date', 'disposal_value', 'disposal_method', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'acquisition_date' => 'date',
            'last_depreciation_date' => 'date',
            'disposal_date' => 'date',
            'acquisition_cost' => 'decimal:2',
            'exchange_rate' => 'decimal:8',
            'cost_in_base' => 'decimal:2',
            'salvage_value' => 'decimal:2',
            'accumulated_depreciation' => 'decimal:2',
            'book_value' => 'decimal:2',
            'disposal_value' => 'decimal:2',
        ];
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function depreciations(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciation::class)->orderByDesc('period');
    }
}
