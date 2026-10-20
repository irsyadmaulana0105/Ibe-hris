<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kampanye email CRM - dikirim lewat mail server sendiri (Stalwart) sebagai
 * marketing@, dengan batas harian keras (config crm.kampanye.batas_harian).
 *
 * crm_campaign_recipients: satu baris per alamat per kampanye. Token dipakai
 *   di tautan berhenti berlangganan; unik global supaya tautan tidak bisa ditebak.
 * crm_email_suppressions: alamat yang TIDAK BOLEH dikirimi kampanye apa pun lagi
 *   (berhenti berlangganan, bounce, atau ditambahkan manual).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('crm_campaigns', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name', 150);
            $t->string('subject', 200);
            $t->string('from_name', 100)->nullable();
            $t->longText('body');                     // teks biasa, placeholder {nama} {perusahaan}
            $t->json('audience')->nullable();         // filter penerima + daftar manual
            $t->string('status', 20)->default('draft'); // draft|sending|paused|done
            $t->unsignedInteger('recipient_count')->default(0);
            $t->char('created_by', 26);
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'status']);
        });

        Schema::create('crm_campaign_recipients', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('campaign_id');
            $t->unsignedBigInteger('company_id');
            $t->string('email', 190);
            $t->string('name', 150)->nullable();
            $t->string('organization', 150)->nullable();
            $t->string('source_type', 20)->nullable(); // contact|lead|company|manual
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('status', 20)->default('pending'); // pending|processing|sent|failed|skipped
            $t->string('token', 48)->unique();
            $t->timestamp('sent_at')->nullable();
            $t->string('error', 500)->nullable();
            $t->timestamps();
            $t->unique(['campaign_id', 'email']);
            $t->index(['campaign_id', 'status']);
            $t->index(['status', 'sent_at']);
        });

        Schema::create('crm_email_suppressions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('email', 190);
            $t->string('reason', 30);                 // unsubscribe|bounce|manual
            $t->unsignedBigInteger('campaign_id')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_email_suppressions');
        Schema::dropIfExists('crm_campaign_recipients');
        Schema::dropIfExists('crm_campaigns');
    }
};
