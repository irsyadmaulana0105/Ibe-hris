<?php

namespace App\Marketing\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deal extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'crm_deals';

    protected $fillable = [
        'company_id', 'code', 'title', 'description', 'pipeline_id', 'stage_id',
        'crm_company_id', 'crm_contact_id', 'owner_id', 'source_lead_id',
        'amount', 'currency', 'probability', 'expected_close_date', 'closed_at',
        'status', 'lost_reason', 'fin_invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'probability' => 'integer',
            'expected_close_date' => 'date',
            'closed_at' => 'date',
        ];
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function crmCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'crm_company_id');
    }

    public function crmContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'crm_contact_id');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subjectable');
    }
}
