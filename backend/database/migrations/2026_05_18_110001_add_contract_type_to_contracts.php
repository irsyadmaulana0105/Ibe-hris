<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tipe kontrak kerja:
 *   - probation   = Karyawan masa percobaan (3 bulan, gaji boleh UMR, hak cuti pro-rata)
 *   - permanent   = PKWTT, karyawan tetap (sudah ada existing logic)
 *   - internship  = Magang (intern_campus / intern_hub / pkl_smk) - uang saku, bukan upah
 *
 * Default backfill: existing contracts → 'permanent' (sebelum migration ini cuma issue PKWTT)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $t) {
            $t->enum('contract_type', ['probation', 'permanent', 'internship'])
                ->default('permanent')
                ->after('contract_number');
            $t->date('end_date')->nullable()->after('start_date');     // probation: +3 bulan, magang: end of period
            $t->unsignedInteger('monthly_amount')->nullable()->after('end_date'); // gaji pokok / uang saku
        });

        // Backfill existing: semua kontrak existing = permanent
        DB::table('contracts')->whereNull('contract_type')->update(['contract_type' => 'permanent']);
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $t) {
            $t->dropColumn(['contract_type', 'end_date', 'monthly_amount']);
        });
    }
};
