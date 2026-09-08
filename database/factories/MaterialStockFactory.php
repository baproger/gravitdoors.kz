<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MaterialUnit;
use App\Models\MaterialStock;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MaterialStock> */
class MaterialStockFactory extends Factory
{
    protected $model = MaterialStock::class;

    public function definition(): array
    {
        return [
            'sku' => mb_strtoupper(fake()->unique()->bothify('??-###')),
            'name' => fake()->words(3, true),
            'unit' => fake()->randomElement(MaterialUnit::cases())->value,
            'quantity' => fake()->randomFloat(2, 10, 500),
            'min_limit' => fake()->randomFloat(2, 5, 40),
            'price_per_unit' => fake()->numberBetween(1, 50) * 1000,
            'is_active' => true,
        ];
    }

    /** Позиция, по которой пора делать закуп. */
    public function belowLimit(): static
    {
        return $this->state(['quantity' => 2, 'min_limit' => 10]);
    }
}
