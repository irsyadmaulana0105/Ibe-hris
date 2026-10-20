<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Integration extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'key', 'name', 'category', 'icon',
        'field_label', 'credential_encrypted', 'status', 'description',
    ];

    protected $hidden = ['credential_encrypted'];

    /**
     * Encrypt/decrypt credential transparently. Frontend POSTs `credential` (plain),
     * we store in `credential_encrypted` (Laravel app key encrypted).
     */
    protected function credential(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->credential_encrypted ? Crypt::decryptString($this->credential_encrypted) : null,
            set: fn ($value) => ['credential_encrypted' => $value ? Crypt::encryptString($value) : null],
        );
    }
}
