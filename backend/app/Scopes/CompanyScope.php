<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class CompanyScope implements Scope
{
    /**
     * Filter records ke company aktif user. Records dengan company_id = NULL
     * dianggap GLOBAL (shared master data - misal pendidikan, hari libur
     * nasional) dan tetap visible ke semua tenant.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (app()->has('active_company_id')) {
            $companyId = app('active_company_id');
            $col = $model->getTable() . '.company_id';
            $builder->where(function (Builder $q) use ($col, $companyId) {
                $q->where($col, $companyId)
                  ->orWhereNull($col);
            });
        }
    }
}
