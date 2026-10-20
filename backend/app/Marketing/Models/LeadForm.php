<?php

namespace App\Marketing\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeadForm extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'crm_lead_forms';

    protected $fillable = [
        'company_id', 'token', 'name', 'description', 'fields',
        'success_message', 'redirect_url', 'notify_emails',
        'default_source', 'default_owner_id', 'is_active',
        'submission_count', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'notify_emails' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(LeadFormSubmission::class, 'form_id');
    }
}
