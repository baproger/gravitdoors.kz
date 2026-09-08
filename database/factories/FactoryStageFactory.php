<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PipelineType;
use App\Models\FactoryStage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FactoryStage> */
class FactoryStageFactory extends Factory
{
    protected $model = FactoryStage::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'pipeline_type' => PipelineType::Sales->value,
            'code' => Str::slug($name, '_'),
            'name' => Str::ucfirst($name),
            'order' => fake()->numberBetween(1, 90) * 10,
            'estimated_hours' => fake()->randomFloat(2, 0, 8),
            'operation_cost' => fake()->numberBetween(0, 10) * 1000,
            'color' => fake()->randomElement(['gray', 'info', 'primary', 'success', 'warning']),
            'is_active' => true,
        ];
    }

    public function factory(): static
    {
        return $this->state(['pipeline_type' => PipelineType::Factory->value]);
    }

    public function triggersProduction(): static
    {
        return $this->state(['triggers_production' => true]);
    }

    public function completesProduction(): static
    {
        return $this->state(['completes_production' => true, 'is_final' => true]);
    }
}
