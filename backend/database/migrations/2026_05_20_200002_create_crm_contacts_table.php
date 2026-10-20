<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Contacts - orang individu, biasanya terkait dengan satu crm_company.
 * Bisa juga floating (independent contact tanpa company).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('crm_contacts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id'); // tenant scoping
            $t->unsignedBigInteger('crm_company_id')->nullable();
            $t->string('first_name', 100);
            $t->string('last_name', 100)->nullable();
            $t->string('position', 150)->nullable();    // job title
            $t->string('email', 150)->nullable();
            $t->string('phone', 50)->nullable();
            $t->string('mobile', 50)->nullable();
            $t->string('linkedin', 200)->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_primary')->default(false);  // primary contact untuk company
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'crm_company_id']);
            $t->index(['company_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_contacts');
    }
};
