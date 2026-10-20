<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->char('user_id', 26)->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->string('document_type');           // contract, certificate, id_card, etc.
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->timestamps();
        });

        Schema::create('document_counters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->string('name');                    // Payroll, Inventory, Assignment
            $table->string('prefix')->nullable();      // GJ, INV, PNS
            $table->unsignedBigInteger('counter')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_counters');
        Schema::dropIfExists('documents');
    }
};
