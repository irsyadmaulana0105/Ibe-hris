<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index('tr_bank_company_idx');
            $table->string('name', 120);
            $table->string('bank_name', 120);
            $table->string('account_number', 80);
            $table->string('currency', 3)->default('IDR');
            $table->decimal('opening_balance', 20, 2)->default(0);
            $table->decimal('current_balance', 20, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->char('created_by', 26)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'bank_name', 'account_number'], 'tr_bank_company_account_uq');
        });

        Schema::create('treasury_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index('tr_payment_company_idx');
            $table->string('reference', 40);
            $table->foreignId('bank_account_id')->constrained('treasury_bank_accounts')->restrictOnDelete();
            $table->string('direction', 10)->default('outbound');
            $table->string('counterparty', 160);
            $table->string('counterparty_account', 100)->nullable();
            $table->decimal('amount', 20, 2);
            $table->string('currency', 3)->default('IDR');
            $table->date('scheduled_for');
            $table->string('description', 500)->nullable();
            $table->string('status', 20)->default('draft')->index('tr_payment_status_idx');
            $table->char('maker_id', 26);
            $table->char('approved_by', 26)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->char('executed_by', 26)->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->string('bank_reference', 120)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'reference'], 'tr_payment_company_ref_uq');
        });

        Schema::create('treasury_cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index('tr_cash_company_idx');
            $table->foreignId('bank_account_id')->constrained('treasury_bank_accounts')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->unique('tr_cash_payment_uq')->constrained('treasury_payments')->restrictOnDelete();
            $table->string('type', 10);
            $table->decimal('amount', 20, 2);
            $table->decimal('balance_after', 20, 2);
            $table->date('transaction_date');
            $table->string('reference', 120);
            $table->string('description', 500)->nullable();
            $table->char('posted_by', 26);
            $table->timestamps();
            $table->index(['company_id', 'bank_account_id', 'transaction_date'], 'tr_cash_account_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_cash_transactions');
        Schema::dropIfExists('treasury_payments');
        Schema::dropIfExists('treasury_bank_accounts');
    }
};
