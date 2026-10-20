<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryWarehouse extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id', 'code', 'name', 'type', 'address', 'city',
        'manager_id', 'is_default', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class, 'warehouse_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'warehouse_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }
}
