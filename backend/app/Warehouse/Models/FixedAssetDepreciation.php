<?php

namespace App\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAssetDepreciation extends Model
{
    public $timestamps = false;

    protected $fillable = ['fixed_asset_id', 'period', 'amount', 'book_value_after', 'posted_by', 'posted_at'];

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'amount' => 'decimal:2',
            'book_value_after' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }
}
