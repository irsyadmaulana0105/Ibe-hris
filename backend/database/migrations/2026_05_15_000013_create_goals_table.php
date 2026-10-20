<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->enum('status', ['on_track', 'at_risk', 'off_track', 'completed'])->default('on_track');
            $table->enum('type', ['objective', 'key_result'])->default('objective');
            $table->string('focus_area', 100)->nullable();
            $table->unsignedBigInteger('parent_goal_id')->nullable();
            $table->foreign('parent_goal_id')->references('id')->on('goals')->cascadeOnDelete();
            $table->char('owner_id', 26)->nullable();
            $table->foreign('owner_id')->references('id')->on('users')->nullOnDelete();
            $table->string('progress_metric', 200)->nullable();
            $table->unsignedTinyInteger('progress_value')->nullable(); // 0-100
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['parent_goal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
