<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Integrasi Reimburse → Finance/Akuntansi.
 *
 * 1. Tambah `expense_account_id` di reimbursement_categories - mapping
 *    kategori reimburse ke akun beban di CoA (fin_accounts). Saat Finance
 *    settle, ReimbursementService bikin Journal Entry: DR <expense_account>,
 *    CR <kas/bank> berdasarkan payment_reference Finance.
 *
 * 2. Tambah `journal_id` ULID di reimbursements - link ke fin_journals
 *    untuk audit trail (klik reimburse di admin -> jump ke jurnal-nya).
 *
 * Note: tidak set FK constraint ke fin_journals karena fin_journals pakai
 * ULID (char 26). Validation di service layer.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('reimbursement_categories', function (Blueprint $t) {
            $t->unsignedBigInteger('expense_account_id')->nullable()->after('limit_amount');
            $t->foreign('expense_account_id')->references('id')->on('fin_accounts')->nullOnDelete();
        });

        Schema::table('reimbursements', function (Blueprint $t) {
            $t->char('journal_id', 26)->nullable()->after('receipt_pdf_path');
            $t->index('journal_id');
        });
    }

    public function down(): void
    {
        Schema::table('reimbursements', function (Blueprint $t) {
            $t->dropIndex(['journal_id']);
            $t->dropColumn('journal_id');
        });

        Schema::table('reimbursement_categories', function (Blueprint $t) {
            $t->dropForeign(['expense_account_id']);
            $t->dropColumn('expense_account_id');
        });
    }
};
