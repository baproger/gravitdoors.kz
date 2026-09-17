<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BonusStatus;
use App\Models\Bonus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Bonus> */
class BonusFactory extends Factory
{
    protected $model = Bonus::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'month' => now()->format('Y-m'),
            'amount' => 20_000,
            'reason' => 'За закрытую сделку',
            'status' => BonusStatus::Pending->value,
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => BonusStatus::Approved->value, 'approved_at' => now()]);
    }
}
