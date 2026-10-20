<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Companies - akun korporat / lead organisasi. Bisa linked ke
 * `fin_customers.id` kalau deal sudah closed-won → auto-create Customer.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('crm_companies', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id'); // tenant scoping
            $t->string('name', 200);
            $t->string('industry', 100)->nullable();
            $t->string('size', 50)->nullable();      // micro/small/medium/large/enterprise
            $t->string('website', 200)->nullable();
            $t->string('phone', 50)->nullable();
            $t->string('email', 150)->nullable();
            $t->text('address')->nullable();
            $t->string('city', 100)->nullable();
            $t->string('province', 100)->nullable();
            $t->string('country', 100)->default('Indonesia');
            $t->text('notes')->nullable();
            // Bidirectional link ke Finance Customer (nullable - terisi pas closed-won)
            $t->unsignedBigInteger('fin_customer_id')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable(); // sales owner (users.id)
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'name']);
            $t->index(['company_id', 'owner_id']);
            $t->index('fin_customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_companies');
    }
};
