<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->string('assignment_number')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->date('date');
            $table->string('title');
            $table->text('details')->nullable();
            $table->enum('status', ['open', 'in_progress', 'completed', 'cancelled'])->default('open');
            $table->timestamps();
        });

        Schema::create('task_assignment_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_assignment_id');
            $table->foreign('task_assignment_id')->references('id')->on('task_assignments')->cascadeOnDelete();
            $table->char('user_id', 26);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('role')->nullable();     // e.g. lead, member, reviewer
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_assignment_items');
        Schema::dropIfExists('task_assignments');
    }
};
