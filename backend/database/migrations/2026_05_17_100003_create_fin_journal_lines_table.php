<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baris Jurnal (Journal Lines) - detail double-entry per akun.
 * Setiap baris HANYA isi debit ATAU credit (tidak boleh keduanya).
 * Sum(debit) must equal sum(credit) per journal (balanced).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_journal_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('journal_id')->index();
            $t->foreignId('account_id')->index();
            $t->decimal('debit', 18, 2)->default(0);
            $t->decimal('credit', 18, 2)->default(0);
            $t->string('memo', 500)->nullable();
            $t->integer('line_order')->default(0);
            $t->timestamps();
            $t->index(['account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_journal_lines');
    }
};
