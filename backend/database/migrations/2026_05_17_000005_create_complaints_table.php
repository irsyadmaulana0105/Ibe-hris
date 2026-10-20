<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer complaint ticket. Sumber data: (1) form public di Web Profile IBE
 * - siapa saja bisa submit, (2) manual entry dari tim produksi (telp/email
 * masuk). Workflow: new → in_progress → resolved | closed.
 *
 * Ticket number format: CMP-{YYYY}-{sequence-padded-4}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaints', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('ticket_number', 32)->unique();
            $t->string('customer_name', 200);
            $t->string('customer_email', 200)->nullable();
            $t->string('customer_phone', 32)->nullable();
            $t->string('customer_company', 200)->nullable();
            $t->string('product_serial', 64)->nullable()->index();
            $t->string('product_name', 200)->nullable();
            $t->string('subject', 200);
            $t->text('description');
            $t->json('attachments')->nullable();           // array of relative storage paths
            $t->string('source', 24)->default('web_form'); // web_form | manual | email | phone
            $t->string('priority', 16)->default('normal'); // low | normal | high | critical
            $t->string('status', 16)->default('new');      // new | in_progress | resolved | closed
            $t->ulid('assigned_to_user_id')->nullable()->index();
            $t->text('resolution_notes')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->ulid('resolved_by_user_id')->nullable();
            $t->string('ip_address', 64)->nullable();
            $t->string('user_agent', 500)->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
    }
};
