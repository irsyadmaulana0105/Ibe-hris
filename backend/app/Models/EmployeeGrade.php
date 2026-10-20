<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeGrade extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = ['company_id', 'name'];

    public function allowance(): HasMany
    {
        return $this->hasMany(Allowance::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
