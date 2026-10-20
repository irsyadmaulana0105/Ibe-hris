<?php

namespace Database\Factories;

use App\Models\Leave;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Leave>
 */
class LeaveFactory extends Factory
{
    protected $model = Leave::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('now', '+30 days');
        return [
            'user_id'    => User::factory(),
            'company_id' => null,
            'leave_type' => fake()->randomElement(array_keys(Leave::$leaveTypes)),
            'start_date' => $start,
            'end_date'   => $start,
            'days_count' => 1,
            'reason'     => fake()->sentence(),
            'status'     => 'pending',
            'notes'      => null,
            'attachment'  => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => 'approved', 'approved_at' => now()]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => ['status' => 'rejected', 'approved_at' => now()]);
    }
}
