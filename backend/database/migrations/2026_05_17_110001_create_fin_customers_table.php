<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer master untuk invoice/faktur. Dipakai oleh fin_invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_customers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('code', 20)->nullable()->index();   // CUST-0001 (auto-gen)
            $t->string('name', 200);
            $t->string('contact_person', 150)->nullable();
            $t->string('email', 150)->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('npwp', 20)->nullable();
            $t->text('address')->nullable();
            $t->string('city', 100)->nullable();
            $t->string('province', 100)->nullable();
            $t->string('postal_code', 10)->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_customers');
    }
};
