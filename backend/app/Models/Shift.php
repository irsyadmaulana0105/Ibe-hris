<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use HasFactory, HasTimestamps, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'name',
        'start_time',
        'end_time',
        'grace_period_minutes',
    ];

    protected $casts = [
        'grace_period_minutes' => 'integer',
    ];

    public function autoShifts(): HasMany
    {
        return $this->hasMany(AutoShift::class);
    }

    public function shiftMappings(): HasMany
    {
        return $this->hasMany(ShiftMapping::class);
    }
}
