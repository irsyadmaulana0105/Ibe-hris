<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sync kontrak ↔ payroll: kontrak permanent IBE Reality menyebut:
 *   - Toleransi keterlambatan ≤ 10 menit (Pasal 3 ayat 3 & Pasal 4 ayat 1)
 *   - Maks 3× telat per bulan (Pasal 4 ayat 2)
 *   - Bonus kehadiran Rp 200k jika hadir ≥ 25 hari + telat ≤ 3× (Pasal 3 ayat 3)
 *   - Telat ke-4+ → sanksi administratif / pembatalan bonus kehadiran
 *
 * Migration ini:
 *   1) Tambah kolom konfigurasi ke `companies` agar rule bisa di-tweak
 *      tanpa ubah kode bila kontrak di-revisi
 *   2) Tambah `payrolls.attendance_bonus_note` untuk audit trail kalau bonus
 *      hangus - operator + karyawan tahu alasannya
 *   3) Backfill: shifts default grace 15 → 10 menit (sesuai kontrak permanent)
 *   4) Set company defaults: 25 hari minimum, 3 max telat - match kontrak
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            // Default grace period yang dipakai kalau shift tidak set sendiri
            if (!Schema::hasColumn('companies', 'late_grace_default_minutes')) {
                $t->unsignedSmallInteger('late_grace_default_minutes')->default(10);
            }
            // Min hari kerja untuk dapat attendance allowance
            if (!Schema::hasColumn('companies', 'attendance_bonus_min_present')) {
                $t->unsignedSmallInteger('attendance_bonus_min_present')->default(25);
            }
            // Max telat dalam sebulan agar bonus tidak hangus
            if (!Schema::hasColumn('companies', 'attendance_bonus_max_late')) {
                $t->unsignedSmallInteger('attendance_bonus_max_late')->default(3);
            }
        });

        Schema::table('payrolls', function (Blueprint $t) {
            if (!Schema::hasColumn('payrolls', 'attendance_bonus_note')) {
                $t->string('attendance_bonus_note', 255)->nullable();
            }
        });

        // Backfill - pakai try/catch defensive karena DB lain (sqlite test) bisa beda
        try {
            // Set semua shift yang masih default 15 → 10 menit (sesuai kontrak permanent)
            // Hanya yang nilai-nya pas 15, tidak override yang sudah di-tweak admin
            DB::statement('UPDATE shifts SET grace_period_minutes = 10 WHERE grace_period_minutes = 15');
        } catch (\Throwable) {
            // ignore - production-only concern (sqlite test env tidak ada data shift)
        }
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $t) {
            $t->dropColumn('attendance_bonus_note');
        });
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn(['late_grace_default_minutes', 'attendance_bonus_min_present', 'attendance_bonus_max_late']);
        });
    }
};
