<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_invoice_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('invoice_id')->index();
            $t->string('item_code', 50)->nullable();
            $t->string('description', 500);
            $t->decimal('quantity', 12, 2)->default(1);
            $t->string('unit', 20)->nullable();
            $t->decimal('unit_price', 18, 2)->default(0);
            $t->decimal('amount', 18, 2)->default(0);   // qty × unit_price
            $t->integer('line_order')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_invoice_lines');
    }
};
