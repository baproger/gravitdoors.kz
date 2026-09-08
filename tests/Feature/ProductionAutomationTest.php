<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\ProductionStatus;
use App\Enums\UserRole;
use App\Events\DealHandedToProduction;
use App\Events\ProductionCompleted;
use App\Exceptions\ProductionException;
use App\Models\Deal;
use App\Models\DoorConfiguration;
use App\Models\FactoryStage;
use App\Models\MaterialStock;
use App\Models\ProductionLog;
use App\Models\User;
use App\Services\DoorProductionService;
use Database\Seeders\DoorOptionSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Две связки воронок из ТЗ: продажи → завод и завод → «Готово к отгрузке». */
class ProductionAutomationTest extends TestCase
{
    use RefreshDatabase;

    private DoorProductionService $production;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);

        $this->production = app(DoorProductionService::class);
    }

    public function test_moving_deal_to_trigger_stage_creates_factory_order(): void
    {
        Event::fake([DealHandedToProduction::class]);

        $deal = $this->makeSalesDeal();

        $this->production->moveToStage($deal, $this->salesStage('handed_to_production'));

        $order = $deal->refresh()->productionOrder;

        $this->assertNotNull($order, 'Наряд завода не создан');
        $this->assertSame(PipelineType::Factory, $order->pipeline_type);
        $this->assertSame($deal->id, $order->parent_deal_id);
        $this->assertSame('metal_cutting', $order->currentStage->code, 'Наряд должен встать на первый этап цеха');
        $this->assertSame(DealStatus::HandedToProduction, $deal->status_id);
        $this->assertSame(DealStatus::InProduction, $order->status_id);

        Event::assertDispatched(DealHandedToProduction::class);
    }

    public function test_completing_qc_stage_moves_sales_deal_to_ready_to_ship(): void
    {
        Event::fake([ProductionCompleted::class]);

        $deal = $this->makeSalesDeal();
        $this->production->moveToStage($deal, $this->salesStage('handed_to_production'));
        $order = $deal->refresh()->productionOrder;

        // Проходим цех до конца: последний этап — «ОТК и Упаковка».
        for ($i = 0; $i < 6; $i++) {
            $order = $this->production->completeCurrentStage($order->refresh());
        }

        $this->assertSame(DealStatus::Completed, $order->refresh()->status_id);
        $this->assertSame(DealStatus::ReadyToShip, $deal->refresh()->status_id, 'Сделка продаж не перешла в «Готово к отгрузке»');
        $this->assertNotNull($deal->production_finished_at);

        Event::assertDispatched(ProductionCompleted::class);
    }

    public function test_dragging_order_past_qc_stage_also_completes_production(): void
    {
        $deal = $this->makeSalesDeal();
        $this->production->moveToStage($deal, $this->salesStage('handed_to_production'));
        $order = $deal->refresh()->productionOrder;

        // Канбан: карточку перетащили сразу на ОТК, затем закрыли этап кнопкой.
        $this->production->moveToStage($order, $this->factoryStage('qc_packing'));
        $this->production->completeCurrentStage($order->refresh());

        $this->assertSame(DealStatus::ReadyToShip, $deal->refresh()->status_id);
    }

    public function test_handoff_is_idempotent(): void
    {
        $deal = $this->makeSalesDeal();

        $first = $this->production->handOffToProduction($deal);
        $second = $this->production->handOffToProduction($deal->refresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Deal::query()->factoryOrders()->count(), 'Повторная передача создала второй наряд');
    }

    public function test_handoff_writes_off_materials_from_stock(): void
    {
        $metal = MaterialStock::query()->where('sku', 'MET-15')->firstOrFail();
        $before = (float) $metal->quantity;

        $deal = $this->makeSalesDeal();
        $this->production->handOffToProduction($deal);

        $after = (float) $metal->refresh()->quantity;

        $this->assertLessThan($before, $after, 'Металл не списался со склада');
        $this->assertDatabaseHas('stock_movements', [
            'material_stock_id' => $metal->id,
            'type' => 'out',
        ]);
    }

    public function test_completed_stage_records_piece_rate_payout(): void
    {
        $worker = User::factory()->create(['role' => UserRole::Worker->value]);

        $deal = $this->makeSalesDeal();
        $this->production->handOffToProduction($deal);
        $order = $deal->refresh()->productionOrder;

        $this->production->startStage($order, $worker);
        $this->production->completeCurrentStage($order->refresh(), $worker);

        $log = ProductionLog::query()
            ->where('deal_id', $order->id)
            ->where('status', ProductionStatus::Done->value)
            ->firstOrFail();

        $this->assertSame($worker->id, $log->worker_id);
        $this->assertEquals(3000, (float) $log->payout, 'Расценка этапа «Раскрой металла» не зафиксирована');
        $this->assertNotNull($log->finished_at);
    }

    public function test_stage_from_another_pipeline_is_rejected(): void
    {
        $deal = $this->makeSalesDeal();

        $this->expectException(ProductionException::class);

        $this->production->moveToStage($deal, $this->factoryStage('welding'));
    }

    public function test_deal_without_configuration_cannot_go_to_production(): void
    {
        $deal = Deal::create([
            'title' => 'Без спецификации',
            'client_name' => 'Клиент',
            'status_id' => DealStatus::New,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => $this->salesStage('new')->id,
        ]);

        $this->expectException(ProductionException::class);

        $this->production->handOffToProduction($deal);
    }

    public function test_closed_deal_cannot_be_moved(): void
    {
        $deal = $this->makeSalesDeal();
        $deal->update(['status_id' => DealStatus::Cancelled]);

        $this->expectException(ProductionException::class);

        $this->production->moveToStage($deal, $this->salesStage('contract'));
    }

    private function makeSalesDeal(): Deal
    {
        $deal = Deal::create([
            'title' => 'ЖК «Тест», кв. 1',
            'client_name' => 'Тестовый клиент',
            'client_phone' => '+7 700 000 00 00',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => $this->salesStage('contract')->id,
        ]);

        DoorConfiguration::create([
            'deal_id' => $deal->id,
            'height' => 2050,
            'width' => 950,
            'opening_side' => 'right',
            'quantity' => 1,
            'metal_thickness' => 'metal_1_5',
            'outer_mdf_panel' => 'outer_mdf_16f',
            'inner_mdf_panel' => 'inner_mdf_10',
            'lock_system' => 'lock_kale',
            'insulation_type' => 'ins_mineral',
            'color_coating' => 'ral_powder',
            'additional_options' => ['peephole'],
        ]);

        return $deal->refresh();
    }

    private function salesStage(string $code): FactoryStage
    {
        return FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', $code)->firstOrFail();
    }

    private function factoryStage(string $code): FactoryStage
    {
        return FactoryStage::query()->ofPipeline(PipelineType::Factory)->where('code', $code)->firstOrFail();
    }
}
