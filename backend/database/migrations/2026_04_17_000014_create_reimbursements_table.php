<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reimbursement_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('limit_amount')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('reimbursements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->char('user_id', 26);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unsignedBigInteger('category_id');
            $table->foreign('category_id')->references('id')->on('reimbursement_categories')->restrictOnDelete();
            $table->date('date');
            $table->text('event')->nullable();
            $table->unsignedBigInteger('unit_price');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedBigInteger('total_amount');
            $table->unsignedBigInteger('approved_amount')->nullable();
            $table->string('attachment_path')->nullable();
            // String is intentional: later workflow migrations add hr_approved,
            // settled, and cancelled. SQLite implements enum as a CHECK
            // constraint that cannot be expanded by the later MySQL ALTER.
            $table->string('status', 32)->default('pending');
            $table->text('notes')->nullable();
            $table->char('approved_by', 26)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reimbursement_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reimbursement_id');
            $table->foreign('reimbursement_id')->references('id')->on('reimbursements')->cascadeOnDelete();
            $table->char('user_id', 26)->nullable();  // for multi-participant items
            $table->text('description')->nullable();
            $table->unsignedBigInteger('amount');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reimbursement_items');
        Schema::dropIfExists('reimbursements');
        Schema::dropIfExists('reimbursement_categories');
    }
};
