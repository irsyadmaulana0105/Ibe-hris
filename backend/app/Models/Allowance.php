<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Allowance extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'employee_grade_id',
        'meal_allowance',
        'transport_allowance',
    ];

    public function employeeGrade(): BelongsTo
    {
        return $this->belongsTo(EmployeeGrade::class);
    }
}
