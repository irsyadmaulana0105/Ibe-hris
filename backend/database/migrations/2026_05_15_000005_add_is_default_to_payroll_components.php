<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mark seeded payroll components as `is_default = true` so the formula
 * evaluator only applies USER-added custom components on top of the
 * hardcoded base calculation. Prevents double-counting basic salary, BPJS,
 * PPh21 when an admin opens Settings → Payroll Formula.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_formula_components', function (Blueprint $t) {
            $t->boolean('is_default')->default(false)->after('taxable');
        });

        // Mark all existing rows as default (they were seeded by HrisEnterpriseSeeder)
        DB::table('payroll_formula_components')->update(['is_default' => true]);
    }

    public function down(): void
    {
        Schema::table('payroll_formula_components', function (Blueprint $t) {
            $t->dropColumn('is_default');
        });
    }
};
