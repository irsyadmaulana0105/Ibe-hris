<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reimbursement workflow upgrade:
 *   1. Karyawan submit dengan bank info (nama bank + nomor rekening + atas nama)
 *   2. HR/Manager approve (status: pending → hr_approved)
 *   3. Finance settle (transfer + upload bukti) → auto-generate kwitansi PDF
 *      → status: hr_approved → settled
 *
 * Status flow: pending → hr_approved → settled
 *              pending → rejected (final)
 *              pending → cancelled (karyawan tarik)
 */
return new class extends Migration {
    public function up(): void
    {
        // 1. Add bank info + settlement columns
        Schema::table('reimbursements', function (Blueprint $table) {
            // Bank info (diisi karyawan saat submit)
            $table->string('bank_name', 100)->nullable()->after('attachment_path');
            $table->string('bank_account_number', 50)->nullable()->after('bank_name');
            $table->string('bank_account_holder', 200)->nullable()->after('bank_account_number');

            // HR approval stage
            $table->char('hr_approved_by', 26)->nullable()->after('approved_at');
            $table->timestamp('hr_approved_at')->nullable()->after('hr_approved_by');
            $table->text('hr_notes')->nullable()->after('hr_approved_at');

            // Finance settlement stage
            $table->char('settled_by', 26)->nullable()->after('hr_notes');
            $table->timestamp('settled_at')->nullable()->after('settled_by');
            $table->string('payment_proof_path', 500)->nullable()->after('settled_at');
            $table->string('payment_reference', 100)->nullable()->after('payment_proof_path');

            // Auto-generated kwitansi
            $table->string('receipt_number', 50)->nullable()->unique()->after('payment_reference');
            $table->string('receipt_pdf_path', 500)->nullable()->after('receipt_number');
        });

        // 2. Expand status enum
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                "ALTER TABLE reimbursements MODIFY status "
                . "ENUM('pending','hr_approved','approved','rejected','settled','cancelled') "
                . "NOT NULL DEFAULT 'pending'"
            );
        }
    }

    public function down(): void
    {
        // Reset status data ke value lama supaya enum bisa di-shrink
        DB::table('reimbursements')->whereIn('status', ['hr_approved', 'settled'])->update(['status' => 'approved']);
        DB::table('reimbursements')->where('status', 'cancelled')->update(['status' => 'rejected']);
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                "ALTER TABLE reimbursements MODIFY status "
                . "ENUM('pending','approved','rejected') "
                . "NOT NULL DEFAULT 'pending'"
            );
        }

        Schema::table('reimbursements', function (Blueprint $table) {
            $table->dropColumn([
                'bank_name', 'bank_account_number', 'bank_account_holder',
                'hr_approved_by', 'hr_approved_at', 'hr_notes',
                'settled_by', 'settled_at',
                'payment_proof_path', 'payment_reference',
                'receipt_number', 'receipt_pdf_path',
            ]);
        });
    }
};
