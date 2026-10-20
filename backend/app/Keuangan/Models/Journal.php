<?php

namespace App\Keuangan\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Journal extends Model
{
    use HasUlids, BelongsToCompany;

    protected $table = 'fin_journals';

    protected $fillable = [
        'company_id', 'number', 'date', 'description', 'reference', 'status',
        'total_debit', 'total_credit',
        'created_by', 'posted_by', 'posted_at',
        'reversed_by', 'reversed_at', 'reverse_reason',
    ];

    protected function casts(): array
    {
        return [
            'date'         => 'date',
            'posted_at'    => 'datetime',
            'reversed_at'  => 'datetime',
            'total_debit'  => 'decimal:2',
            'total_credit' => 'decimal:2',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_order');
    }
}
