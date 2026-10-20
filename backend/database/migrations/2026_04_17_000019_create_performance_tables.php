<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('weight');          // bobot (0-100)
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('performance_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->char('user_id', 26);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('performance_type_id');
            $table->foreign('performance_type_id')->references('id')->on('performance_types')->restrictOnDelete();
            $table->unsignedSmallInteger('score');
            $table->unsignedSmallInteger('running_score')->default(0);
            $table->string('reference_type')->nullable();   // polymorphic: attendance, task, etc.
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_reports');
        Schema::dropIfExists('performance_types');
    }
};
