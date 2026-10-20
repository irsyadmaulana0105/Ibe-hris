<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->char('user_id', 26);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->string('payroll_number')->nullable();        // nomor slip gaji
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('year');
            $table->decimal('attendance_percentage', 5, 2)->default(0);

            // Earnings
            $table->unsignedBigInteger('base_salary')->default(0);
            $table->unsignedBigInteger('meal_transport_total')->default(0);
            $table->unsignedBigInteger('overtime_count')->default(0);
            $table->unsignedBigInteger('overtime_rate')->default(0);
            $table->unsignedBigInteger('overtime_total')->default(0);
            $table->unsignedBigInteger('attendance_count')->default(0);
            $table->unsignedBigInteger('attendance_rate')->default(0);
            $table->unsignedBigInteger('attendance_total')->default(0);
            $table->unsignedBigInteger('bonus_count')->default(0);
            $table->unsignedBigInteger('bonus_rate')->default(0);
            $table->unsignedBigInteger('bonus_total')->default(0);
            $table->unsignedBigInteger('thr_count')->default(0);
            $table->unsignedBigInteger('thr_rate')->default(0);
            $table->unsignedBigInteger('thr_total')->default(0);
            $table->unsignedBigInteger('reimbursement_total')->default(0);

            // Deductions
            $table->unsignedBigInteger('late_count')->default(0);
            $table->unsignedBigInteger('late_rate')->default(0);
            $table->unsignedBigInteger('late_total')->default(0);
            $table->unsignedBigInteger('absent_count')->default(0);
            $table->unsignedBigInteger('absent_rate')->default(0);
            $table->unsignedBigInteger('absent_total')->default(0);
            $table->unsignedBigInteger('leave_count')->default(0);
            $table->unsignedBigInteger('leave_rate')->default(0);
            $table->unsignedBigInteger('leave_total')->default(0);
            $table->bigInteger('salary_advance_balance')->default(0);
            $table->unsignedBigInteger('salary_advance_payment')->default(0);

            // Totals
            $table->unsignedBigInteger('gross_total')->default(0);
            $table->unsignedBigInteger('deduction_total')->default(0);
            $table->bigInteger('net_total')->default(0);

            $table->enum('status', ['draft', 'submitted', 'approved', 'paid'])->default('draft');
            $table->timestamps();

            $table->unique(['user_id', 'month', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};
