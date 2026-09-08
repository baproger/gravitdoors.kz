<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProductionStatus;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\ProductionLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductionLog> */
class ProductionLogFactory extends Factory
{
    protected $model = ProductionLog::class;

    public function definition(): array
    {
        return [
            'deal_id' => Deal::factory()->factoryOrder(),
            'stage_id' => FactoryStage::factory()->factory(),
            'started_at' => now()->subHours(4),
            'status' => ProductionStatus::Pending->value,
            'payout' => 0,
        ];
    }

    public function done(): static
    {
        return $this->state([
            'status' => ProductionStatus::Done->value,
            'finished_at' => now(),
            'payout' => 5000,
        ]);
    }
}
