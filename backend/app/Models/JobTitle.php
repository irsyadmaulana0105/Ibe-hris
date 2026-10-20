<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JobTitle extends Model
{
    use HasFactory, HasTimestamps, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'name',
        'division_id',
    ];

    public function users()
    {
        return $this->hasMany(\App\Models\User::class);
    }

    public function division()
    {
        return $this->belongsTo(\App\Models\Division::class);
    }
}
