<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jurnal Umum (General Journal) - header transaksi double-entry bookkeeping.
 *
 *   draft   = sedang disusun, bisa edit/delete
 *   posted  = sudah final, masuk Buku Besar, tidak bisa edit (audit trail)
 *
 * Format nomor: JV-2026/05/0001 (Journal Voucher, year, month, sequence)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_journals', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('number', 32)->index();              // JV-2026/05/0001
            $t->date('date')->index();
            $t->string('description', 500);
            $t->string('reference', 100)->nullable();       // ref dokumen eksternal (invoice no, dll)
            $t->enum('status', ['draft', 'posted', 'reversed'])->default('draft')->index();
            $t->decimal('total_debit', 18, 2)->default(0);
            $t->decimal('total_credit', 18, 2)->default(0);
            $t->ulid('created_by')->nullable();
            $t->ulid('posted_by')->nullable();
            $t->timestamp('posted_at')->nullable();
            $t->ulid('reversed_by')->nullable();
            $t->timestamp('reversed_at')->nullable();
            $t->string('reverse_reason', 500)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_journals');
    }
};
