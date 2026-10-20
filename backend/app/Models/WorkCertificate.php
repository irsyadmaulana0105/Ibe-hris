<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkCertificate extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'cert_number', 'purpose',
        'issued_date', 'issued_by', 'signer_name', 'signer_title',
        'pdf_path', 'verification_hash',
    ];

    protected $casts = [
        'issued_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
