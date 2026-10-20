<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Buang baris kedaluwarsa dari tabel operasional yang tumbuh tanpa batas.
 *
 * Yang di-prune di sini HANYA data operasional sekali-pakai — token yang
 * sudah lewat masa berlaku dan telemetri shadow-mode RBAC. `audit_logs`
 * SENGAJA TIDAK termasuk: itu bukti audit/compliance, dan lajunya masih
 * wajar (~40rb baris/tahun, ~20 MB). Kalau nanti perlu, arsipkan dulu ke
 * cold storage sebelum menghapus apa pun dari sana.
 *
 * Kolom acuan berbeda per tabel: token dinilai dari `expires_at` (kapan
 * benar-benar tidak berguna lagi), telemetri dari `created_at`.
 */
class PruneOperationalTables extends Command
{
    protected $signature = 'db:prune-operational
        {--dry-run : Hitung saja, tidak menghapus}
        {--chunk=2000 : Jumlah baris per batch delete}';

    protected $description = 'Hapus token kedaluwarsa + telemetri lama dari tabel operasional (audit_logs dikecualikan)';

    /**
     * table => [kolom acuan, umur simpan dalam hari, alasan]
     *
     * Retensi sengaja longgar dari kebutuhan teknis supaya masih ada jejak
     * untuk investigasi insiden, tapi tetap memotong pertumbuhan jangka panjang.
     */
    private const TARGETS = [
        'sso_tokens' => ['expires_at', 7, 'token handoff SSO, umur asli hitungan detik'],
        'refresh_tokens' => ['expires_at', 30, 'refresh token kedaluwarsa'],
        'rbac_access_decisions' => ['created_at', 90, 'telemetri shadow-mode RBAC'],
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $chunk = max(100, (int) $this->option('chunk'));
        $totalDeleted = 0;

        foreach (self::TARGETS as $table => [$column, $days, $why]) {
            if (! Schema::hasTable($table)) {
                $this->warn("skip {$table}: tabel tidak ada");

                continue;
            }
            if (! Schema::hasColumn($table, $column)) {
                $this->warn("skip {$table}: kolom {$column} tidak ada");

                continue;
            }

            $cutoff = now()->subDays($days);
            $candidates = DB::table($table)->where($column, '<', $cutoff)->count();

            if ($candidates === 0) {
                $this->line("  {$table}: bersih (retensi {$days} hari)");

                continue;
            }

            if ($dry) {
                $this->line("  {$table}: {$candidates} baris akan dihapus (>{$days} hari, {$why})");

                continue;
            }

            // Batch supaya tidak mengunci tabel lama-lama di jam sibuk.
            $deleted = 0;
            do {
                $n = DB::table($table)->where($column, '<', $cutoff)->limit($chunk)->delete();
                $deleted += $n;
            } while ($n > 0);

            $totalDeleted += $deleted;
            $this->info("  {$table}: {$deleted} baris dihapus (>{$days} hari, {$why})");
        }

        $this->line($dry ? 'dry-run selesai, tidak ada yang dihapus.' : "selesai: {$totalDeleted} baris dihapus.");

        return self::SUCCESS;
    }
}
