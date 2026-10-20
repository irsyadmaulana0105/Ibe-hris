<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integrasi Payroll → Finance/Akuntansi.
 *
 * Saat HR/Finance markPaid, auto-create posted Journal Entry per payroll:
 *   DR `5210 Beban Gaji & Tunjangan` = gross_total
 *   CR `1120 Bank`                   = net_total
 *   CR `1160 Piutang Karyawan`       = salary_advance_payment (potongan kasbon, kalau ada)
 *   CR `2130 Hutang Pajak (PPh21/PPN)` = sisa potongan (pajak + BPJS karyawan + late/absent)
 *
 * Link journal_id supaya audit trail bisa jump dari payroll detail ke jurnal-nya.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $t) {
            $t->char('journal_id', 26)->nullable()->after('status');
            $t->index('journal_id');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $t) {
            $t->dropIndex(['journal_id']);
            $t->dropColumn('journal_id');
        });
    }
};
