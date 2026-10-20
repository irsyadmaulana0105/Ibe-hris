<?php

namespace App\Keuangan\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use BelongsToCompany;

    protected $table = 'fin_customers';

    protected $fillable = [
        'company_id', 'code', 'name', 'contact_person', 'email', 'phone',
        'npwp', 'address', 'city', 'province', 'postal_code', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
