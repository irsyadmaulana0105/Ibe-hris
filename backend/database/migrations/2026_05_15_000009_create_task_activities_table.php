<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
            $table->char('user_id', 26)->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            // 'created', 'updated', 'commented', 'status_changed', 'assigned', 'sprint_moved'
            $table->string('action', 40);
            $table->string('field', 50)->nullable();
            $table->text('from_value')->nullable();
            $table->text('to_value')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_activities');
    }
};
