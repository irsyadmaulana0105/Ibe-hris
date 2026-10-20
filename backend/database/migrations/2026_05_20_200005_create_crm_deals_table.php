<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Deals - opportunity / sales pipeline item.
 *
 * Deal stage berpindah dari awal pipeline → 'won' atau 'lost'.
 * Saat masuk stage type='won':
 *   - status di-set 'won' otomatis
 *   - closed_at di-stamp
 *   - hook ke FinanceCustomerService: create fin_customer (kalau belum) + draft invoice
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('crm_deals', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id'); // tenant scoping
            $t->string('code', 30);              // DEAL-2026-0001
            $t->string('title', 200);
            $t->text('description')->nullable();

            // Relasi
            $t->unsignedBigInteger('pipeline_id');
            $t->unsignedBigInteger('stage_id');
            $t->unsignedBigInteger('crm_company_id')->nullable();
            $t->unsignedBigInteger('crm_contact_id')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable(); // sales rep
            $t->unsignedBigInteger('source_lead_id')->nullable(); // kalau di-promote dari lead

            // Money & forecast
            $t->decimal('amount', 18, 2)->default(0);
            $t->string('currency', 3)->default('IDR');
            $t->unsignedTinyInteger('probability')->default(50); // 0-100, default ikut stage
            $t->date('expected_close_date')->nullable();
            $t->date('closed_at')->nullable();

            $t->enum('status', ['open', 'won', 'lost'])->default('open');
            $t->string('lost_reason', 100)->nullable(); // price/competitor/timing/no_fit/other

            // Auto-fill saat won: link ke fin_invoices.id (ULID - draft yang di-generate)
            $t->ulid('fin_invoice_id')->nullable();

            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'code']);
            $t->index(['company_id', 'pipeline_id', 'stage_id']);
            $t->index(['company_id', 'owner_id', 'status']);
            $t->index(['company_id', 'expected_close_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_deals');
    }
};
