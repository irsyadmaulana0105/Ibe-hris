<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_advances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->char('user_id', 26);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('amount');
            $table->text('purpose');
            $table->enum('status', ['pending', 'approved', 'rejected', 'paid'])->default('pending');
            $table->text('notes')->nullable();
            $table->char('approved_by', 26)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_advances');
    }
};
