<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faktur Penjualan (Sales Invoice). Auto-create journal saat status berubah:
 *   draft   → tidak ada journal
 *   sent    → DR Piutang (1130), CR Penjualan (4110), CR Hutang PPN (2130)
 *   paid    → DR Kas/Bank, CR Piutang (1130)
 *   cancelled → reverse journal sent (kalau sudah sent)
 *
 * Nomor: INV-{YYYY}/{MM}/{4-digit}
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_invoices', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('number', 32)->index();
            $t->date('date')->index();
            $t->date('due_date')->nullable();
            $t->foreignId('customer_id')->nullable()->index();
            $t->string('customer_name', 200);          // snapshot supaya tahan kalau customer dihapus
            $t->string('customer_npwp', 20)->nullable();
            $t->text('customer_address')->nullable();
            $t->text('description')->nullable();
            $t->string('reference', 100)->nullable();
            $t->decimal('subtotal', 18, 2)->default(0);
            $t->decimal('discount', 18, 2)->default(0);
            $t->decimal('tax_rate', 5, 2)->default(11);    // PPN 11% default
            $t->decimal('tax_amount', 18, 2)->default(0);
            $t->decimal('total', 18, 2)->default(0);
            $t->decimal('paid_amount', 18, 2)->default(0);
            $t->enum('status', ['draft', 'sent', 'paid', 'partial', 'cancelled', 'overdue'])
                ->default('draft')->index();
            $t->ulid('sent_journal_id')->nullable();
            $t->ulid('payment_journal_id')->nullable();
            $t->ulid('created_by')->nullable();
            $t->ulid('sent_by')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->ulid('paid_by')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->string('payment_account_code', 20)->nullable();   // 1110 Kas / 1120 Bank
            $t->timestamps();
            $t->unique(['company_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_invoices');
    }
};
