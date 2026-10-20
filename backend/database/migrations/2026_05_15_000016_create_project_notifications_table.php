<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Project-app notifications (separate from Laravel's built-in notifications
     * table; that one is reserved by other features and uses Notifiable / Database
     * channel). This is a simpler, app-scoped inbox.
     */
    public function up(): void
    {
        Schema::create('project_notifications', function (Blueprint $table) {
            $table->id();
            $table->char('user_id', 26); // recipient
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->char('actor_id', 26)->nullable();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            // 'mention', 'assigned', 'comment_replied', 'dependency_added', 'task_completed'
            $table->string('type', 40);
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_notifications');
    }
};
