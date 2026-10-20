<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Serial number untuk produk robot IBE. Tim produksi (role=produksi) generate
 * SN baru saat unit selesai dirakit. SN dipakai customer untuk klaim warranty
 * + lapor complaint. Format default: IBE-{PRODUCT_CODE}-{YYYYMM}-{4-digit-seq}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_serial_numbers', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('serial_number', 64)->unique();
            $t->string('product_code', 32)->index();     // e.g. RBT, ARM, AUTO
            $t->string('product_name', 120);
            $t->string('product_variant', 80)->nullable();
            $t->string('customer_name', 200)->nullable();  // issued to
            $t->string('customer_email', 200)->nullable();
            $t->string('customer_phone', 32)->nullable();
            $t->date('issued_at')->nullable();             // ship/handover date
            $t->date('warranty_until')->nullable();
            $t->string('status', 16)->default('active');   // active, recalled, retired
            $t->text('notes')->nullable();
            $t->ulid('generated_by_user_id')->index();
            $t->timestamps();
            $t->index(['company_id', 'product_code', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_serial_numbers');
    }
};
