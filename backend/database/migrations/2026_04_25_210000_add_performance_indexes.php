<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Attendance: queries always filter by user_id + date together
        // (no company_id column on this table - scoped via user relationship)
        Schema::table('attendances', function (Blueprint $table) {
            $table->index(['user_id', 'date'], 'attendances_user_date_idx');
        });

        // Leaves: admin lists filter by company_id + status; employee by user_id + status
        Schema::table('leaves', function (Blueprint $table) {
            $table->index(['user_id', 'status'], 'leaves_user_status_idx');
            $table->index(['company_id', 'status'], 'leaves_company_status_idx');
        });

        // Payrolls: admin queries always filter company + month + year together
        Schema::table('payrolls', function (Blueprint $table) {
            $table->index(['company_id', 'month', 'year'], 'payrolls_company_period_idx');
            $table->index(['user_id', 'status'], 'payrolls_user_status_idx');
        });

        // Overtimes: same approval-list patterns as leaves
        Schema::table('overtimes', function (Blueprint $table) {
            $table->index(['user_id', 'status'], 'overtimes_user_status_idx');
            $table->index(['company_id', 'status'], 'overtimes_company_status_idx');
        });

        // Salary advances
        Schema::table('salary_advances', function (Blueprint $table) {
            $table->index(['user_id', 'status'], 'salary_advances_user_status_idx');
            $table->index(['company_id', 'status'], 'salary_advances_company_status_idx');
        });

        // Reimbursements
        Schema::table('reimbursements', function (Blueprint $table) {
            $table->index(['user_id', 'status'], 'reimbursements_user_status_idx');
            $table->index(['company_id', 'status'], 'reimbursements_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex('attendances_user_date_idx');
        });

        Schema::table('leaves', function (Blueprint $table) {
            $table->dropIndex('leaves_user_status_idx');
            $table->dropIndex('leaves_company_status_idx');
        });

        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropIndex('payrolls_company_period_idx');
            $table->dropIndex('payrolls_user_status_idx');
        });

        Schema::table('overtimes', function (Blueprint $table) {
            $table->dropIndex('overtimes_user_status_idx');
            $table->dropIndex('overtimes_company_status_idx');
        });

        Schema::table('salary_advances', function (Blueprint $table) {
            $table->dropIndex('salary_advances_user_status_idx');
            $table->dropIndex('salary_advances_company_status_idx');
        });

        Schema::table('reimbursements', function (Blueprint $table) {
            $table->dropIndex('reimbursements_user_status_idx');
            $table->dropIndex('reimbursements_company_status_idx');
        });
    }
};
