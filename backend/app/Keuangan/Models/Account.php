<?php

namespace App\Keuangan\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Akun pembukuan (Chart of Accounts entry). Isolated dari User/HR models -
 * Finance module pakai namespace App\Keuangan\* supaya gak coupled dengan HR.
 */
class Account extends Model
{
    use BelongsToCompany;

    protected $table = 'fin_accounts';

    protected $fillable = [
        'company_id', 'code', 'name', 'type', 'normal_balance',
        'parent_id', 'is_header', 'is_active', 'description',
    ];

    protected function casts(): array
    {
        return [
            'is_header' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** Default normal_balance per type (untuk auto-fill saat create). */
    public static function defaultNormalBalance(string $type): string
    {
        return in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit';
    }
}
