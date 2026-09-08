<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DoorOptionCategory;
use App\Enums\PriceType;
use App\Models\DoorOption;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DoorOption> */
class DoorOptionFactory extends Factory
{
    protected $model = DoorOption::class;

    public function definition(): array
    {
        $label = fake()->unique()->words(2, true);

        return [
            'category' => DoorOptionCategory::Additional->value,
            'code' => Str::slug($label, '_'),
            'label' => Str::ucfirst($label),
            'price' => fake()->numberBetween(1, 30) * 1000,
            'price_type' => PriceType::Fixed->value,
            'consumption' => 0,
            'sort' => fake()->numberBetween(1, 50) * 10,
            'is_active' => true,
        ];
    }

    public function perSquareMeter(): static
    {
        return $this->state(['price_type' => PriceType::PerSquareMeter->value]);
    }
}
