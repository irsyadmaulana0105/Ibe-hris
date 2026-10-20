<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Division extends Model
{
    use HasFactory, HasTimestamps, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'name',
        'default_shift_id',
    ];

    public function users()
    {
        return $this->hasMany(\App\Models\User::class);
    }

    public function defaultShift()
    {
        return $this->belongsTo(\App\Models\Shift::class, 'default_shift_id');
    }
}
