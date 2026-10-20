<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log untuk tiap form submission. Mengikuti pola payload-snapshot -
 * payload aslinya disimpan as-is (JSON) supaya kalau ada disputed lead,
 * bisa dilihat persis yang submitter ketik.
 *
 * lead_id nullable: kalau form validation fail atau lead gak ter-create
 * karena alasan apapun, submission tetap di-log untuk diagnostic.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('crm_lead_form_submissions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('form_id');
            $t->unsignedBigInteger('lead_id')->nullable();
            $t->string('ip_address', 45)->nullable();    // IPv6 fits 45 chars
            $t->string('user_agent', 500)->nullable();
            $t->string('referer', 500)->nullable();
            $t->json('payload');                          // raw submitted data
            $t->boolean('is_spam')->default(false);       // honeypot/rate-limit flagged
            $t->string('spam_reason', 100)->nullable();   // "honeypot", "rate_limit", etc
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id', 'form_id', 'created_at']);
            $t->index(['ip_address', 'created_at']);      // for rate limit lookup
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_form_submissions');
    }
};
