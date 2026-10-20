<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class LeaveHoliday extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'date', 'name', 'type'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
