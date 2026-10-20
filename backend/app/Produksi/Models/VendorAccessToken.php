<?php

namespace App\Produksi\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorAccessToken extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'vendor_id', 'token', 'expires_at', 'revoked_at', 'last_used_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
