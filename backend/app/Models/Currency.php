<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['code', 'name', 'symbol', 'decimals', 'is_active', 'is_base'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_base'   => 'boolean',
            'decimals'  => 'integer',
        ];
    }
}
