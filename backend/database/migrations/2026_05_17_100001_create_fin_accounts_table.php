<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chart of Accounts (CoA) - Bagan Akun untuk Finance Module.
 *
 * ISOLATED dari sistem HR/Payroll existing. Prefix `fin_` supaya jelas
 * mana tabel finance vs HRIS. Tidak modify table existing.
 *
 * Standar PSAK Indonesia:
 *   1xxx Aset (Asset)
 *   2xxx Kewajiban (Liability)
 *   3xxx Modal (Equity)
 *   4xxx Pendapatan (Revenue)
 *   5xxx Beban (Expense)
 *
 * Hierarchical (parent_id) supaya bisa nested: 1100 Aset Lancar > 1110 Kas
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('code', 20)->index();                // e.g. '1100', '1110'
            $t->string('name', 200);
            $t->enum('type', ['asset', 'liability', 'equity', 'revenue', 'expense'])->index();
            $t->enum('normal_balance', ['debit', 'credit']);  // asset/expense=debit, lainnya=credit
            $t->foreignId('parent_id')->nullable()->index();
            $t->boolean('is_header')->default(false);       // header = tidak bisa di-post journal, hanya grouping
            $t->boolean('is_active')->default(true);
            $t->text('description')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_accounts');
    }
};
