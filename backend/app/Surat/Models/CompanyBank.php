<?php

namespace App\Surat\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CompanyBank extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'bank_name', 'account_number', 'account_name',
        'branch', 'swift_code', 'currency', 'notes',
        'is_default', 'is_active', 'display_order',
    ];

    protected function casts(): array
    {
        return [
            'is_default'    => 'boolean',
            'is_active'     => 'boolean',
            'display_order' => 'integer',
        ];
    }

    /**
     * Render bank info untuk substitusi {{company.bank}}.
     * Format: "{bank} {nomor rekening} a.n. {nama pemilik}"
     */
    public function asResolvedString(): string
    {
        $parts = [
            $this->bank_name,
            $this->account_number,
            'a.n. ' . $this->account_name,
        ];
        return trim(implode(' ', array_filter($parts)));
    }
}
