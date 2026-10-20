<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PerformanceType extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'weight', 'description'];

    public function reports(): HasMany
    {
        return $this->hasMany(PerformanceReport::class);
    }
}
