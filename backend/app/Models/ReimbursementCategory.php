<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReimbursementCategory extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'limit_amount', 'is_active', 'expense_account_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function reimbursements(): HasMany
    {
        return $this->hasMany(Reimbursement::class, 'category_id');
    }
}
