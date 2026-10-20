<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->string('type', 32)->index();
            $table->string('reference', 64);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('open')->index();
            $table->string('severity', 16)->nullable();
            $table->unsignedTinyInteger('likelihood')->nullable();
            $table->unsignedTinyInteger('impact')->nullable();
            $table->char('owner_id', 26)->nullable()->index();
            $table->date('due_date')->nullable()->index();
            $table->json('details')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'reference']);
            $table->index(['company_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_records');
    }
};
