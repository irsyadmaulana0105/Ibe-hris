<?php

namespace App\Marketing\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stage extends Model
{
    use BelongsToCompany;

    protected $table = 'crm_stages';

    protected $fillable = [
        'company_id', 'pipeline_id', 'name', 'color', 'type', 'sort_order', 'default_probability',
    ];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }
}
