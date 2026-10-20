<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyModuleEntitlement extends Model
{
    protected $fillable = ['company_id', 'module_key', 'enabled', 'source', 'limits', 'valid_from', 'valid_until', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'limits' => 'array', 'valid_from' => 'datetime', 'valid_until' => 'datetime'];
    }
}
