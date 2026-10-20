<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Leads - prospek mentah sebelum jadi qualified deal.
 * Workflow: new → contacted → qualified (→ promote ke Deal) | disqualified.
 * Setelah promote, lead.converted_deal_id terisi → lead di-archive.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('crm_leads', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id'); // tenant scoping
            $t->string('name', 150);              // person name atau company name
            $t->string('email', 150)->nullable();
            $t->string('phone', 50)->nullable();
            $t->string('company_name', 200)->nullable();
            $t->string('source', 50)->nullable(); // website/referral/cold_call/event/social
            $t->enum('status', ['new', 'contacted', 'qualified', 'disqualified'])->default('new');
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable();
            // Saat lead di-convert jadi deal, link disimpan supaya history kebaca
            $t->unsignedBigInteger('converted_deal_id')->nullable();
            $t->timestamp('converted_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'status']);
            $t->index(['company_id', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_leads');
    }
};
