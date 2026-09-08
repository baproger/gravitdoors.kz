<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Models\Deal;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Deal> */
class DealFactory extends Factory
{
    protected $model = Deal::class;

    public function definition(): array
    {
        $total = fake()->numberBetween(120, 400) * 1000;

        return [
            'title' => 'ЖК «'.fake()->word().'», кв. '.fake()->numberBetween(1, 200),
            'client_name' => fake()->name(),
            'client_phone' => '+7 7'.fake()->numerify('## ### ## ##'),
            'client_address' => fake()->address(),
            'total_price' => $total,
            'cost_price' => (int) round($total * 0.7),
            'status_id' => DealStatus::New,
            'pipeline_type' => PipelineType::Sales->value,
            'due_date' => fake()->dateTimeBetween('+1 week', '+2 months'),
        ];
    }

    public function factoryOrder(): static
    {
        return $this->state([
            'pipeline_type' => PipelineType::Factory->value,
            'status_id' => DealStatus::InProduction,
        ]);
    }
}
