<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier + Bill (Hutang / Accounts Payable). Mirror dari invoice tapi
 * direction kebalikan: kita yang hutang ke supplier.
 *
 * Status bills:
 *   draft    → tidak ada journal
 *   received → DR Beban/Aset (5xxx/1xxx), DR PPN Masukan, CR Hutang Usaha (2110)
 *   paid     → DR Hutang Usaha (2110), CR Kas/Bank
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_suppliers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('code', 20)->nullable()->index();
            $t->string('name', 200);
            $t->string('contact_person', 150)->nullable();
            $t->string('email', 150)->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('npwp', 20)->nullable();
            $t->text('address')->nullable();
            $t->string('bank_name', 100)->nullable();
            $t->string('bank_account_number', 50)->nullable();
            $t->string('bank_account_name', 150)->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'code']);
        });

        Schema::create('fin_bills', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('number', 32)->index();          // BILL-{YYYY}/{MM}/{4-digit}
            $t->date('date')->index();
            $t->date('due_date')->nullable();
            $t->foreignId('supplier_id')->nullable()->index();
            $t->string('supplier_name', 200);
            $t->string('supplier_invoice_no', 100)->nullable(); // nomor invoice dari supplier
            $t->text('description')->nullable();
            $t->decimal('subtotal', 18, 2)->default(0);
            $t->decimal('tax_rate', 5, 2)->default(0);   // PPN masukan, default 0 (banyak supplier non-PKP)
            $t->decimal('tax_amount', 18, 2)->default(0);
            $t->decimal('total', 18, 2)->default(0);
            $t->decimal('paid_amount', 18, 2)->default(0);
            $t->enum('status', ['draft', 'received', 'paid', 'partial', 'cancelled', 'overdue'])
                ->default('draft')->index();
            $t->ulid('received_journal_id')->nullable();
            $t->ulid('payment_journal_id')->nullable();
            $t->ulid('created_by')->nullable();
            $t->ulid('received_by')->nullable();
            $t->timestamp('received_at')->nullable();
            $t->ulid('paid_by')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->string('payment_account_code', 20)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'number']);
        });

        Schema::create('fin_bill_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('bill_id')->index();
            $t->foreignId('expense_account_id');         // akun beban/aset 5xxx atau 1xxx
            $t->string('description', 500);
            $t->decimal('quantity', 12, 2)->default(1);
            $t->string('unit', 20)->nullable();
            $t->decimal('unit_price', 18, 2)->default(0);
            $t->decimal('amount', 18, 2)->default(0);
            $t->integer('line_order')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_bill_lines');
        Schema::dropIfExists('fin_bills');
        Schema::dropIfExists('fin_suppliers');
    }
};
