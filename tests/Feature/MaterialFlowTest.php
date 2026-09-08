<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Exceptions\ProductionException;
use App\Models\Deal;
use App\Models\DoorConfiguration;
use App\Models\FactoryStage;
use App\Models\MaterialStock;
use App\Models\StockMovement;
use App\Services\DoorProductionService;
use Database\Seeders\DoorOptionSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Склад должен сходиться: что списали под наряд — то и вернулось при отмене. */
class MaterialFlowTest extends TestCase
{
    use RefreshDatabase;

    private DoorProductionService $production;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);

        $this->production = app(DoorProductionService::class);
    }

    public function test_cancelling_an_order_returns_materials_to_stock(): void
    {
        $metal = MaterialStock::query()->where('sku', 'MET-15')->firstOrFail();
        $before = (float) $metal->quantity;

        $deal = $this->makeDeal();
        $order = $this->production->handOffToProduction($deal);

        $this->assertLessThan($before, (float) $metal->refresh()->quantity);

        $this->production->cancelProduction($order);

        $this->assertEqualsWithDelta($before, (float) $metal->refresh()->quantity, 0.001, 'Металл не вернулся на склад');
        $this->assertSame(DealStatus::Cancelled, $order->refresh()->status_id);
        $this->assertSame(DealStatus::InWork, $deal->refresh()->status_id, 'Сделка клиента должна вернуться в работу');
    }

    public function test_returning_materials_twice_does_not_double_the_stock(): void
    {
        $metal = MaterialStock::query()->where('sku', 'MET-15')->firstOrFail();
        $before = (float) $metal->quantity;

        $deal = $this->makeDeal();
        $order = $this->production->handOffToProduction($deal);

        $this->production->cancelProduction($order);
        $this->production->returnMaterials($order->refresh());

        $this->assertEqualsWithDelta($before, (float) $metal->refresh()->quantity, 0.001, 'Повторный возврат задвоил остаток');
    }

    public function test_write_off_covers_every_position_of_the_deal(): void
    {
        $metal = MaterialStock::query()->where('sku', 'MET-15')->firstOrFail();
        $before = (float) $metal->quantity;

        $deal = $this->makeDeal();
        // Вторая дверь того же металла: списание должно вырасти, а не остаться прежним.
        $this->addPosition($deal, 2);

        $this->production->handOffToProduction($deal->refresh());

        $written = $before - (float) $metal->refresh()->quantity;
        $singleDoor = round(0.36 * (2.05 * 0.95), 3);

        $this->assertEqualsWithDelta($singleDoor * 2, $written, 0.01, 'Списан металл только на одну дверь');
    }

    public function test_write_off_aggregates_one_movement_per_material(): void
    {
        $deal = $this->makeDeal();
        $this->addPosition($deal, 2);

        $order = $this->production->handOffToProduction($deal->refresh());

        $metalId = MaterialStock::query()->where('sku', 'MET-15')->value('id');

        $movements = StockMovement::query()
            ->where('deal_id', $order->id)
            ->where('material_stock_id', $metalId)
            ->count();

        $this->assertSame(1, $movements, 'На один материал должна быть одна строка расхода');
    }

    public function test_deal_price_sums_all_positions(): void
    {
        $deal = $this->makeDeal();
        $this->production->syncPricing($deal->refresh());
        $onePosition = (float) $deal->refresh()->total_price;

        $this->addPosition($deal, 2);
        $this->production->syncPricing($deal->refresh());
        $twoPositions = (float) $deal->refresh()->total_price;

        $this->assertGreaterThan($onePosition, $twoPositions);
        $this->assertSame(2, $deal->doorsCount());
    }

    public function test_insufficient_stock_blocks_handoff_when_configured(): void
    {
        config()->set('gravit.production.allow_negative_stock', false);
        MaterialStock::query()->where('sku', 'MET-15')->update(['quantity' => 0]);

        $deal = $this->makeDeal();

        $this->expectException(ProductionException::class);

        $this->production->handOffToProduction($deal);
    }

    private function makeDeal(): Deal
    {
        $deal = Deal::create([
            'title' => 'Заказ на две двери',
            'client_name' => 'Клиент',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', 'contract')->value('id'),
        ]);

        $this->addPosition($deal, 1);

        return $deal->refresh();
    }

    private function addPosition(Deal $deal, int $position): DoorConfiguration
    {
        return DoorConfiguration::create([
            'deal_id' => $deal->id,
            'position' => $position,
            'height' => 2050,
            'width' => 950,
            'opening_side' => 'right',
            'quantity' => 1,
            'metal_thickness' => 'metal_1_5',
            'lock_system' => 'lock_kale',
        ]);
    }
}
