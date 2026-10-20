<?php

namespace App\Traits;

use App\Models\Company;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope());

        static::creating(function (self $model) {
            if (empty($model->company_id) && app()->has('active_company_id')) {
                $model->company_id = app('active_company_id');
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeForCompany(mixed $query, int $companyId): mixed
    {
        return $query->withoutGlobalScope(CompanyScope::class)
            ->where($this->getTable() . '.company_id', $companyId);
    }
}
