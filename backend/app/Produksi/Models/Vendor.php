<?php

namespace App\Produksi\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vendor extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'pic_name', 'pic_email', 'pic_phone', 'tax_id', 'address'];

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function accessTokens(): HasMany
    {
        return $this->hasMany(VendorAccessToken::class);
    }
}
