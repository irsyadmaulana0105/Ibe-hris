<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_grade_id')->nullable()->after('job_title_id');
            $table->foreign('employee_grade_id')->references('id')->on('employee_grades')->nullOnDelete();

            $table->unsignedBigInteger('tax_status_id')->nullable()->after('employee_grade_id');
            $table->foreign('tax_status_id')->references('id')->on('tax_statuses')->nullOnDelete();

            $table->unsignedBigInteger('office_location_id')->nullable()->after('tax_status_id');
            $table->foreign('office_location_id')->references('id')->on('office_locations')->nullOnDelete();

            $table->enum('marital_status', ['single', 'married', 'divorced', 'widowed'])
                ->nullable()
                ->after('gender');

            $table->string('bank_account')->nullable()->after('salary_monthly');

            // Per-employee salary components (override grade defaults)
            $table->unsignedBigInteger('meal_transport_rate')->default(0)->after('bank_account');
            $table->unsignedBigInteger('overtime_rate')->default(0)->after('meal_transport_rate');  // per hour
            $table->unsignedBigInteger('attendance_allowance')->default(0)->after('overtime_rate');
            $table->unsignedBigInteger('late_deduction')->default(0)->after('attendance_allowance');     // per occurrence
            $table->unsignedBigInteger('absent_deduction')->default(0)->after('late_deduction');         // per day
            $table->unsignedBigInteger('leave_deduction')->default(0)->after('absent_deduction');
            $table->unsignedBigInteger('bonus_rate')->default(0)->after('leave_deduction');
            $table->unsignedBigInteger('thr_allowance')->default(0)->after('bonus_rate');

            // Running balances
            $table->bigInteger('salary_advance_balance')->default(0)->after('thr_allowance');
            $table->unsignedTinyInteger('annual_leave_balance')->default(12)->after('salary_advance_balance');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['employee_grade_id']);
            $table->dropForeign(['tax_status_id']);
            $table->dropForeign(['office_location_id']);
            $table->dropColumns([
                'employee_grade_id', 'tax_status_id', 'office_location_id',
                'marital_status', 'bank_account',
                'meal_transport_rate', 'overtime_rate', 'attendance_allowance',
                'late_deduction', 'absent_deduction', 'leave_deduction',
                'bonus_rate', 'thr_allowance',
                'salary_advance_balance', 'annual_leave_balance',
            ]);
        });
    }
};
