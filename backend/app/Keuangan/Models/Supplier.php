<?php

namespace App\Keuangan\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use BelongsToCompany;

    protected $table = 'fin_suppliers';

    protected $fillable = [
        'company_id', 'code', 'name', 'contact_person', 'email', 'phone',
        'npwp', 'address',
        'bank_name', 'bank_account_number', 'bank_account_name',
        'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }
}
