<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integrasi Kasbon (SalaryAdvance) → Finance/Akuntansi.
 *
 * Saat Finance approve kasbon (= dicairkan), auto-create posted Journal Entry:
 *   DR `1160 Piutang Karyawan` (atau auto-create kalau belum ada)
 *   CR `1110 Kas`
 *
 * Link `journal_id` di salary_advances supaya audit trail bisa jump dari kasbon
 * detail ke jurnal-nya.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('salary_advances', function (Blueprint $t) {
            $t->char('journal_id', 26)->nullable()->after('approved_at');
            $t->index('journal_id');
        });
    }

    public function down(): void
    {
        Schema::table('salary_advances', function (Blueprint $t) {
            $t->dropIndex(['journal_id']);
            $t->dropColumn('journal_id');
        });
    }
};
