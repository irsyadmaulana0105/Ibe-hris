<?php

namespace App\Marketing\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'crm_contacts';

    protected $fillable = [
        'company_id', 'crm_company_id', 'first_name', 'last_name', 'position',
        'email', 'phone', 'mobile', 'linkedin', 'notes', 'is_primary', 'owner_id',
    ];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function crmCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'crm_company_id');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'crm_contact_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
