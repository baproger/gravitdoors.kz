<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DebtCategory;
use App\Enums\DebtStatus;
use App\Models\Debt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Debt> */
class DebtFactory extends Factory
{
    protected $model = Debt::class;

    public function definition(): array
    {
        return [
            'counterparty' => $this->faker->company(),
            'category' => DebtCategory::Supplier->value,
            'amount' => $this->faker->numberBetween(100_000, 2_000_000),
            'due_at' => now()->addWeeks(2)->toDateString(),
            'status' => DebtStatus::Open->value,
        ];
    }
}
