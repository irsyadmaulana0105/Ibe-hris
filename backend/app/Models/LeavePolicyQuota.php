<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class LeavePolicyQuota extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'type_key', 'name', 'permanent', 'contract', 'intern'];
}
