<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_quotas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('annual_leave')->default(12);
            $table->unsignedTinyInteger('sick_leave')->default(12);
            $table->unsignedTinyInteger('personal_leave')->default(3);
            $table->unsignedTinyInteger('medical_checkup')->default(2);
            $table->unsignedTinyInteger('late_allowance')->default(3);     // max allowed lates
            $table->unsignedTinyInteger('early_leave_allowance')->default(3);
            $table->unsignedTinyInteger('other_leave')->default(2);
            $table->timestamps();

            $table->unique(['company_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_quotas');
    }
};
