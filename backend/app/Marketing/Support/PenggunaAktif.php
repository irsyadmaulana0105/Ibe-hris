<?php

namespace App\Marketing\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Saringan "pengguna aktif" untuk CRM yang aman terhadap employment_status NULL.
 *
 * User::scopeActive() memakai `employment_status NOT IN ('resigned','terminated')`.
 * Di SQL, `NULL NOT IN (...)` tidak bernilai benar, sehingga akun tanpa status
 * kepegawaian - termasuk akun bersama "Marketing" yang justru dipakai untuk
 * CRM - dianggap tidak aktif (ditemukan 4 Okt 2026). Akibatnya akun itu tidak
 * bisa jadi penanggung jawab lead dan tidak menerima pemberitahuan.
 *
 * scopeActive() sengaja tidak diubah: ia juga dipakai penggajian, dan
 * mengubah semantiknya perlu ditinjau tersendiri.
 */
final class PenggunaAktif
{
    public static function saring(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $w) => $w->whereNull('employment_status')
                ->orWhereNotIn('employment_status', User::INACTIVE_STATUSES))
            ->whereNull('resigned_at');
    }
}
