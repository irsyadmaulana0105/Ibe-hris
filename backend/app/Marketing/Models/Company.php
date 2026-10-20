<?php

namespace App\Marketing\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'crm_companies';

    protected $fillable = [
        'company_id', 'name', 'industry', 'size', 'website', 'phone', 'email',
        'address', 'city', 'province', 'country', 'notes', 'fin_customer_id', 'owner_id',
    ];

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'crm_company_id');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'crm_company_id');
    }
}
