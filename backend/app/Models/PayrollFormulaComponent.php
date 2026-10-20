<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PayrollFormulaComponent extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'name', 'category', 'formula',
        'method', 'taxable', 'is_default', 'sort_order', 'min_working_days',
    ];

    protected function casts(): array
    {
        return ['taxable' => 'boolean', 'is_default' => 'boolean'];
    }
}
