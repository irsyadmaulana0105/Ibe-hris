<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allowances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->unsignedBigInteger('employee_grade_id');
            $table->foreign('employee_grade_id')->references('id')->on('employee_grades')->cascadeOnDelete();
            $table->unsignedBigInteger('meal_allowance')->default(0);
            $table->unsignedBigInteger('transport_allowance')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allowances');
    }
};
