<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tier potongan terlambat (range menit telat → nominal potongan).
 *
 *   user_id NULL  → default tier untuk seluruh karyawan di company
 *   user_id ada   → override khusus untuk user tsb (jadi prioritas)
 *
 * Lookup di payroll: `tiersForUser($user)->forMinutes($lateMinutes)`.
 *
 * @property int $id
 * @property int $company_id
 * @property string|null $user_id
 * @property int $min_minutes
 * @property int|null $max_minutes
 * @property int $deduction_amount
 * @property string|null $label
 * @property int $sort_order
 */
class LateDeductionTier extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'user_id', 'min_minutes', 'max_minutes',
        'deduction_amount', 'label', 'sort_order',
    ];

    protected $casts = [
        'min_minutes'      => 'integer',
        'max_minutes'      => 'integer',
        'deduction_amount' => 'integer',
        'sort_order'       => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Cari tier yang matching untuk menit telat tertentu (per-user override
     * mengalahkan company-default kalau ada).
     *
     * Logic match: min_minutes <= $minutes AND (max_minutes IS NULL OR $minutes < max_minutes).
     * Inclusive lower, exclusive upper agar boundary jelas (15-30 menit = [15, 30)).
     */
    public static function findForUser(User $user, int $minutes): ?self
    {
        if ($minutes <= 0) return null;

        // Prefer per-user override
        $userTier = static::query()
            ->where('user_id', $user->id)
            ->where('min_minutes', '<=', $minutes)
            ->where(fn (Builder $q) => $q->whereNull('max_minutes')->orWhere('max_minutes', '>', $minutes))
            ->orderByDesc('min_minutes')
            ->first();
        if ($userTier) return $userTier;

        // Fallback company default
        return static::query()
            ->whereNull('user_id')
            ->where('company_id', $user->company_id)
            ->where('min_minutes', '<=', $minutes)
            ->where(fn (Builder $q) => $q->whereNull('max_minutes')->orWhere('max_minutes', '>', $minutes))
            ->orderByDesc('min_minutes')
            ->first();
    }
}
