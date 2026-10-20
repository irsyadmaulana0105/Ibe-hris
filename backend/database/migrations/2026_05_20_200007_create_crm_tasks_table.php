<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Tasks - follow-up reminders dengan due date.
 * Beda dari Activities: tasks punya scheduled date + status pending/done,
 * activities cuma log apa yang sudah terjadi.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('crm_tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->enum('type', ['call', 'email', 'meeting', 'follow_up', 'other'])->default('follow_up');
            $t->string('subjectable_type', 100)->nullable();
            $t->unsignedBigInteger('subjectable_id')->nullable();
            $t->unsignedBigInteger('assignee_id')->nullable();
            $t->date('due_date')->nullable();
            $t->time('due_time')->nullable();
            $t->enum('priority', ['low', 'normal', 'high'])->default('normal');
            $t->enum('status', ['pending', 'done', 'canceled'])->default('pending');
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'assignee_id', 'status', 'due_date']);
            $t->index(['company_id', 'subjectable_type', 'subjectable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_tasks');
    }
};
