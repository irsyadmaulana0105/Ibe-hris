<?php

namespace App\Marketing\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'crm_leads';

    protected $fillable = [
        'company_id', 'name', 'email', 'phone', 'company_name', 'source', 'status',
        'notes', 'owner_id', 'converted_deal_id', 'converted_at',
        'budget_score', 'authority_score', 'need_score', 'timeline_score', 'score',
    ];

    protected function casts(): array
    {
        return ['converted_at' => 'datetime'];
    }

    public function convertedDeal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'converted_deal_id');
    }
}
