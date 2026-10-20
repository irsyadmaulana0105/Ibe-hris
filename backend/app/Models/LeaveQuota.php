<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveQuota extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'year',
        'annual_leave',
        'sick_leave',
        'personal_leave',
        'medical_checkup',
        'late_allowance',
        'early_leave_allowance',
        'other_leave',
    ];
}
