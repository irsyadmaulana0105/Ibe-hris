<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web Forms - lead capture publik (mirip Krayin web forms).
 * Admin bikin form di CRM, dapat embed code untuk di-paste di landing page
 * atau iframe widget. Submission otomatis create Lead di crm_leads.
 *
 * Token: URL-safe random string, dipake di public URL
 *        /api/v1/crm/public/forms/{token}/submit
 *        Token ganti = form lama unreachable (rotation untuk security).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('crm_lead_forms', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('token', 64)->unique();       // public URL identifier
            $t->string('name', 150);                 // admin label
            $t->text('description')->nullable();
            // Array of field configs: [{key, label, type, required, options?, placeholder?}]
            $t->json('fields');
            $t->text('success_message')->nullable(); // shown after submit
            $t->string('redirect_url', 500)->nullable(); // if set, redirect after submit
            $t->json('notify_emails')->nullable();   // emails to notify on submission
            $t->string('default_source', 50)->nullable(); // overrides Lead.source
            $t->char('default_owner_id', 26)->nullable(); // assign to specific user (ULID)
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('submission_count')->default(0); // cached counter
            $t->char('created_by', 26);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_forms');
    }
};
