<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankStatement extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'bank_name', 'account_number',
        'period_from', 'period_to',
        'file_path', 'original_name', 'mime', 'size',
        'notes', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'size' => 'integer',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
