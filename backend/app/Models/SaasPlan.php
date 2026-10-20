<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaasPlan extends Model
{
    protected $fillable = ['key', 'name', 'description', 'user_limit', 'monthly_price', 'yearly_price', 'currency', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'user_limit' => 'integer', 'monthly_price' => 'decimal:2', 'yearly_price' => 'decimal:2'];
    }

    public function modules(): HasMany
    {
        return $this->hasMany(SaasPlanModule::class, 'plan_id');
    }
}
