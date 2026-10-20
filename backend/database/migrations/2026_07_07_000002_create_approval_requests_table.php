<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval requests - permintaan persetujuan eksekutif ke Direktur.
 * Kategori:
 *   expense       - pengeluaran besar > threshold (default Rp 10jt)
 *   vendor_contract - kontrak dengan vendor / supplier
 *   hr_policy     - perubahan kebijakan HR strategis
 *   other         - persetujuan umum lainnya
 *
 * Lifecycle: pending → approved | rejected (final)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id');
            $t->string('type', 32); // expense, vendor_contract, hr_policy, other
            $t->string('title');
            $t->text('description');
            $t->decimal('amount', 18, 2)->nullable(); // untuk expense/vendor_contract
            $t->json('attachments')->nullable(); // list file paths
            $t->string('status', 16)->default('pending'); // pending, approved, rejected
            $t->string('requested_by');    // user_id HR/staff
            $t->string('approver_id')->nullable(); // user_id direktur yang approve
            $t->text('approver_notes')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('rejected_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->timestamps();

            $t->index(['company_id', 'status']);
            $t->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
