<?php

namespace Database\Factories;

use App\Models\Payroll;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payroll>
 */
class PayrollFactory extends Factory
{
    protected $model = Payroll::class;

    public function definition(): array
    {
        $user = User::factory()->create(['group' => 'user']);

        return [
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'payroll_number' => 'GJ/2026/04/' . $this->faker->unique()->numberBetween(10000, 99999),
            'period_start' => '2026-03-28',
            'period_end' => '2026-04-27',
            'month' => 4,
            'year' => 2026,
            'attendance_percentage' => 100,
            'base_salary' => 5000000,
            'meal_transport_total' => 0,
            'overtime_count' => 0,
            'overtime_rate' => 0,
            'overtime_total' => 0,
            'attendance_count' => 22,
            'attendance_rate' => 0,
            'attendance_total' => 0,
            'bonus_count' => 1,
            'bonus_rate' => 0,
            'bonus_total' => 0,
            'thr_count' => 0,
            'thr_rate' => 0,
            'thr_total' => 0,
            'reimbursement_total' => 0,
            'late_count' => 0,
            'late_rate' => 0,
            'late_total' => 0,
            'absent_count' => 0,
            'absent_rate' => 0,
            'absent_total' => 0,
            'leave_count' => 0,
            'leave_rate' => 0,
            'leave_total' => 0,
            'salary_advance_balance' => 0,
            'salary_advance_payment' => 0,
            'gross_total' => 5000000,
            'deduction_total' => 0,
            'net_total' => 5000000,
            'status' => 'draft',
        ];
    }
}
