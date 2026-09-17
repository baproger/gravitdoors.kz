<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Models\Expense;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Expense> */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        return [
            'category' => ExpenseCategory::Other->value,
            'amount' => $this->faker->numberBetween(5_000, 300_000),
            'spent_at' => now()->toDateString(),
            'method' => PaymentMethod::Cash->value,
            'counterparty' => $this->faker->company(),
            'receipt_path' => 'expenses/receipt.jpg',
            'status' => ExpenseStatus::Pending->value,
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => ExpenseStatus::Approved->value, 'approved_at' => now()]);
    }
}
