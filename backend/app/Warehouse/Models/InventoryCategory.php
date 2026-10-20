<?php

namespace App\Warehouse\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryCategory extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = ['company_id', 'name', 'budget_limit', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class, 'category_id');
    }
}
