<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Activities - timeline log per deal/contact/company.
 * Polymorphic subject - bisa di-attach ke deal, contact, atau company.
 * Untuk follow-up dengan due date pakai crm_tasks (terpisah karena beda UX).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('crm_activities', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->enum('type', ['call', 'email', 'meeting', 'note', 'whatsapp']);
            $t->string('subject', 200)->nullable();
            $t->text('body')->nullable();
            // Polymorphic subject
            $t->string('subjectable_type', 100); // deal | contact | company | lead
            $t->unsignedBigInteger('subjectable_id');
            $t->unsignedBigInteger('user_id'); // siapa yang log
            $t->timestamp('happened_at')->nullable(); // kapan kejadiannya (bukan create time)
            $t->timestamps();
            $t->index(['company_id', 'subjectable_type', 'subjectable_id']);
            $t->index(['company_id', 'user_id', 'happened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_activities');
    }
};
