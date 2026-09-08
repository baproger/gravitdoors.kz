<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MaterialStock;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockMovement> */
class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    public function definition(): array
    {
        return [
            'material_stock_id' => MaterialStock::factory(),
            'type' => StockMovement::TYPE_OUT,
            'quantity' => fake()->randomFloat(3, 0.5, 20),
            'price_per_unit' => fake()->numberBetween(1, 40) * 1000,
        ];
    }

    public function incoming(): static
    {
        return $this->state(['type' => StockMovement::TYPE_IN]);
    }
}
