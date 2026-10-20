<?php

namespace Database\Factories;

use App\Models\Overtime;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Overtime>
 */
class OvertimeFactory extends Factory
{
    protected $model = Overtime::class;

    public function definition(): array
    {
        return [
            'user_id'        => User::factory(),
            'company_id'     => null,
            'date'           => fake()->dateTimeBetween('-30 days', 'now'),
            'check_in_time'  => '17:00',
            'check_out_time' => '19:00',
            'total_hours'    => 2.00,
            'reason'         => fake()->sentence(),
            'status'         => 'pending',
            'notes'          => null,
            'attachment'     => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => 'approved', 'approved_at' => now()]);
    }
}
