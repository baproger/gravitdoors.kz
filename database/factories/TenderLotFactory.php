<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DoorCategory;
use App\Enums\DoorModel;
use App\Enums\TenderLotResult;
use App\Models\Tender;
use App\Models\TenderLot;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TenderLot> */
class TenderLotFactory extends Factory
{
    protected $model = TenderLot::class;

    public function definition(): array
    {
        return [
            'tender_id' => Tender::factory(),
            'lot_number' => (string) fake()->numberBetween(1, 20),
            'name' => 'Дверь металлическая входная',
            'category' => DoorCategory::Comfort,
            'model' => DoorModel::cases()[0],
            'height' => 2050,
            'width' => 950,
            'quantity' => 40,
            'budget_unit_price' => 180_000,
            'bid_unit_price' => 165_000,
            'result' => TenderLotResult::Pending,
        ];
    }

    public function won(): static
    {
        return $this->state(['result' => TenderLotResult::Won]);
    }
}
