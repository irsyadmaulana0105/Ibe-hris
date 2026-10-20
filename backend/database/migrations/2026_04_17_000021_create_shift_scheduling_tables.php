<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // auto_shifts: default shift per job title
        Schema::create('auto_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->unsignedBigInteger('job_title_id');
            $table->foreign('job_title_id')->references('id')->on('job_titles')->cascadeOnDelete();
            $table->unsignedBigInteger('shift_id');
            $table->foreign('shift_id')->references('id')->on('shifts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['job_title_id', 'shift_id']);
        });

        // shift_mappings: per-user shift overrides for specific dates
        Schema::create('shift_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->char('user_id', 26);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unsignedBigInteger('shift_id');
            $table->foreign('shift_id')->references('id')->on('shifts')->cascadeOnDelete();
            $table->date('date');
            $table->timestamps();

            $table->unique(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_mappings');
        Schema::dropIfExists('auto_shifts');
    }
};
