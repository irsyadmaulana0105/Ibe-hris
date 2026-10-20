<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah threshold minimum hari kerja untuk komponen payroll formula.
 *
 * Use case: Bonus Absen hanya berlaku kalau working_days >= N (mis. 20 hari).
 * Kalau kurang dari N, komponen return 0 (tidak masuk ke gross).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_formula_components', function (Blueprint $t) {
            if (!Schema::hasColumn('payroll_formula_components', 'min_working_days')) {
                $t->unsignedInteger('min_working_days')->nullable()->after('sort_order')
                    ->comment('Jika set, komponen hanya berlaku saat working_days >= nilai ini');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_formula_components', function (Blueprint $t) {
            if (Schema::hasColumn('payroll_formula_components', 'min_working_days')) {
                $t->dropColumn('min_working_days');
            }
        });
    }
};
