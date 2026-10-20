<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Satisfaction Survey (NPS - Net Promoter Score).
 *
 * Workflow: kirim survey ke customer setelah shipment delivered or service ticket
 * resolved. Customer respond via public link (token-based).
 *
 *   nps_score 0-10:
 *     - 9-10 = Promoter
 *     - 7-8  = Passive
 *     - 0-6  = Detractor
 *
 * NPS = % Promoters - % Detractors
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_surveys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('survey_token', 64)->unique();         // for public response link
            $t->foreignId('customer_id')->nullable()->index();
            $t->string('customer_name', 200);
            $t->string('customer_email', 200)->nullable();
            $t->string('trigger_type', 32);                   // shipment_delivered|ticket_resolved|invoice_paid|manual
            $t->string('trigger_ref_table', 64)->nullable();  // shipments | complaints | fin_invoices
            $t->unsignedBigInteger('trigger_ref_id')->nullable();
            $t->date('sent_date');
            $t->timestamp('responded_at')->nullable();
            $t->integer('nps_score')->nullable();             // 0-10
            $t->string('nps_category', 16)->nullable();       // promoter|passive|detractor
            $t->text('comment')->nullable();
            $t->json('ratings')->nullable();                  // {"product_quality": 5, "service": 4, "timeliness": 5}
            $t->string('status', 16)->default('sent');        // sent|responded|expired|cancelled
            $t->ulid('sent_by')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status', 'sent_date']);
            $t->index(['company_id', 'responded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_surveys');
    }
};
