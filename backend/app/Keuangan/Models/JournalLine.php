<?php

namespace App\Keuangan\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    use HasUlids;

    protected $table = 'fin_journal_lines';

    protected $fillable = [
        'journal_id', 'account_id', 'debit', 'credit', 'memo', 'line_order',
    ];

    protected function casts(): array
    {
        return [
            'debit'  => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
