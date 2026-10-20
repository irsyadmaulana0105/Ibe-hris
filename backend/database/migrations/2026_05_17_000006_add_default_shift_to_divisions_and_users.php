<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah `default_shift_id` ke divisions DAN users. Resolution priority saat
 * sistem cari shift karyawan:
 *
 *   1. ShiftMapping per-day (override paling spesifik)
 *   2. users.default_shift_id (per-user permanent override)
 *   3. AutoShift per job_title (jabatan default)
 *   4. divisions.default_shift_id (divisi default)
 *   5. Company first shift (fallback)
 *
 * Tujuan: HR cuma perlu set shift sekali per divisi → semua karyawan di
 * bawahnya auto ikut. Tetap bisa override per-user / per-day kalau perlu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('divisions', function (Blueprint $t) {
            $t->unsignedBigInteger('default_shift_id')->nullable()->after('name')->index();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->unsignedBigInteger('default_shift_id')->nullable()->after('job_title_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('divisions', function (Blueprint $t) {
            $t->dropColumn('default_shift_id');
        });
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('default_shift_id');
        });
    }
};
