<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DoorCategory;
use App\Enums\DoorModel;
use App\Enums\OpeningSide;
use App\Models\Deal;
use App\Models\DoorConfiguration;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DoorConfiguration> */
class DoorConfigurationFactory extends Factory
{
    protected $model = DoorConfiguration::class;

    public function definition(): array
    {
        return [
            'deal_id' => Deal::factory(),
            'position' => 1,
            'category' => fake()->randomElement(DoorCategory::cases())->value,
            'model' => fake()->randomElement(DoorModel::cases())->value,
            'height' => fake()->randomElement([1900, 2050, 2200]),
            'width' => fake()->randomElement([860, 900, 950, 1050]),
            'opening_side' => fake()->randomElement(OpeningSide::cases())->value,
            'quantity' => 1,
            'metal_thickness' => 'metal_1_5',
            'outer_mdf_panel' => 'outer_mdf_16f',
            'inner_mdf_panel' => 'inner_mdf_10',
            'lock_system' => 'lock_kale',
            'insulation_type' => 'ins_mineral',
            'color_coating' => 'ral_powder',
            'additional_options' => [],
        ];
    }
}
