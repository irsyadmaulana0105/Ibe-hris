<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Broadcast pengumuman dari direksi/HR ke karyawan. Audience bisa di-filter
 * by group, division, employment_status, atau specific user_ids (OR-combined).
 * Disimpan agar admin bisa audit siapa pernah mengumumkan apa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcasts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->ulid('sent_by_user_id')->index();
            $t->string('subject', 200);
            $t->text('body');
            $t->string('priority', 16)->default('normal'); // normal | important | urgent
            $t->json('audience_filter');                   // {all,groups,division_ids,employment_statuses,user_ids}
            $t->boolean('send_email')->default(true);
            $t->integer('recipient_count')->default(0);
            $t->integer('email_sent_count')->default(0);
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcasts');
    }
};
