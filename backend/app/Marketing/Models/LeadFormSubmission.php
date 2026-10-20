<?php

namespace App\Marketing\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadFormSubmission extends Model
{
    use BelongsToCompany;

    public $timestamps = false; // only created_at

    protected $table = 'crm_lead_form_submissions';

    protected $fillable = [
        'company_id', 'form_id', 'lead_id', 'ip_address', 'user_agent',
        'referer', 'payload', 'is_spam', 'spam_reason',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'is_spam' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(LeadForm::class, 'form_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
