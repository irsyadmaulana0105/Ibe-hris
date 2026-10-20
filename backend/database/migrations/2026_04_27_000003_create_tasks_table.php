<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('status', ['todo', 'in_progress', 'in_review', 'completed'])->default('todo');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->char('assigned_to', 26)->nullable();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['assigned_to']);
            $table->index(['due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
