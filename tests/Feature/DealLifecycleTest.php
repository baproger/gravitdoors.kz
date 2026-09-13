<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealEventType;
use App\Enums\DealStatus;
use App\Enums\Department;
use App\Enums\PaymentMethod;
use App\Enums\PipelineType;
use App\Enums\ProductionStatus;
use App\Enums\UserRole;
use App\Exceptions\ProductionException;
use App\Models\Deal;
use App\Models\DoorConfiguration;
use App\Models\DoorOption;
use App\Models\FactoryStage;
use App\Models\MaterialStock;
use App\Models\ProductionLog;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\DoorPriceCalculator;
use App\Services\DoorProductionService;
use Database\Seeders\DoorOptionSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Сквозной сценарий: сделка от заявки до закрытия. На каждом шаге сверяются
 * все побочные эффекты — цены, склад, оплаты, зарплата, история, статусы.
 * Если что-то из этого разойдётся, разойдётся и учёт у компании.
 */
class DealLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private DoorProductionService $production;

    private User $manager;

    private User $master;

    private User $worker;

    private User $surveyor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);
        $this->production = app(DoorProductionService::class);

        $this->manager = User::factory()->create(['role' => UserRole::Manager->value, 'name' => 'Менеджер']);
        $this->master = User::factory()->create(['role' => UserRole::Master->value, 'name' => 'Мастер']);
        $this->worker = User::factory()->create(['role' => UserRole::Worker->value, 'name' => 'Рабочий']);
        $this->surveyor = User::factory()->create(['role' => UserRole::Surveyor->value, 'name' => 'Замерщик']);

        $this->actingAs($this->manager);
    }

    public function test_full_lifecycle_keeps_every_number_consistent(): void
    {
        // --- 1. Заявка с двумя разными дверями ---------------------------------
        $deal = $this->newDeal();
        $doorA = $this->door($deal, ['category' => 'premium', 'model' => 'lion', 'width' => 950, 'quantity' => 1,
            'metal_thickness' => 'metal_1_5', 'outer_mdf_panel' => 'outer_mdf_16f', 'lock_system' => 'lock_kale_border',
            'insulation_type' => 'ins_mineral', 'color_coating' => 'ral_powder', 'additional_options' => ['peephole']]);
        $doorB = $this->door($deal, ['category' => 'comfort', 'model' => 'agora', 'width' => 860, 'quantity' => 2,
            'metal_thickness' => 'metal_1_2', 'lock_system' => 'lock_kale', 'insulation_type' => 'ins_eps']);

        $this->assertSame([1, 2], $deal->doorConfigurations()->pluck('position')->all(), 'Позиции нумеруются сами');

        $deal->update(['delivery_cost' => 15_000, 'installation_cost' => 25_000]);
        $this->production->syncPricing($deal);
        $deal->refresh();

        $calculator = app(DoorPriceCalculator::class);
        $doorsTotal = $calculator->calculateFor($doorA)->total + $calculator->calculateFor($doorB)->total;
        $this->assertGreaterThan(100_000, $doorsTotal);
        $this->assertEqualsWithDelta($doorsTotal + 40_000, (float) $deal->total_price, 0.01, 'Сумма = двери + доставка + монтаж');

        // --- 2. Дата замера уведомляет замерщика --------------------------------
        $deal->update(['measured_at' => now()->addDay()]);
        $this->assertSame(1, $this->surveyor->notifications()->count());
        $this->assertSame(0, $this->manager->notifications()->count());

        // --- 3. Воронка продаж по регламенту ---------------------------------
        $this->production->moveToStage($deal->refresh(), $this->sales('measurement'), $this->manager);
        $this->production->moveToStage($deal->refresh(), $this->sales('contract'), $this->manager);

        // --- 4. Оплата с чеком становится предоплатой -----------------------------
        $deal->payments()->create(['amount' => 100_000, 'method' => PaymentMethod::Kaspi, 'paid_at' => now(), 'receipt_path' => 'receipts/1.pdf']);
        $deal->refresh();
        $this->assertEqualsWithDelta(100_000, (float) $deal->prepayment, 0.01);
        $this->assertEqualsWithDelta((float) $deal->total_price - 100_000, $deal->remainingPayment(), 0.01);

        // --- 5. В производство — только с договором и документом ---------------
        try {
            $this->production->moveToStage($deal->refresh(), $this->sales('handed_to_production'), $this->manager);
            $this->fail('Без договора сделку пустили в производство');
        } catch (ProductionException $e) {
            $this->assertStringContainsString('Номер договора', $e->getMessage());
        }

        $deal->update(['contract_number' => 'ДГ-1', 'contract_date' => now(), 'documents' => ['deals/contract.pdf']]);
        $stockBefore = MaterialStock::query()->pluck('quantity', 'id')->map(fn ($q): float => (float) $q);

        $this->production->moveToStage($deal->refresh(), $this->sales('handed_to_production'), $this->manager);
        $deal->refresh();
        $order = $deal->productionOrder;

        $this->assertNotNull($order);
        $this->assertStringStartsWith('PRD-', $order->number);
        $this->assertSame(DealStatus::HandedToProduction, $deal->status_id);
        $this->assertSame(DealStatus::InProduction, $order->status_id);
        $this->assertSame('metal_cutting', $order->currentStage->code);
        $this->assertEqualsWithDelta((float) $deal->total_price, (float) $order->total_price, 0.01, 'Наряд несёт сумму сделки');

        // Первый этап открыт, но исполнитель ещё не назначен: менеджер дверь не режет.
        $firstLog = ProductionLog::query()->where('deal_id', $order->id)->firstOrFail();
        $this->assertNull($firstLog->worker_id);

        // --- 6. Склад: списано ровно столько, сколько требует каждая опция -------
        $expected = $this->expectedMaterials([$doorA, $doorB]);
        $this->assertNotEmpty($expected);

        $written = StockMovement::query()
            ->where('deal_id', $order->id)->where('type', StockMovement::TYPE_OUT)
            ->get()->groupBy('material_stock_id')->map(fn ($m): float => round((float) $m->sum('quantity'), 3));

        foreach ($expected as $materialId => $qty) {
            $this->assertEqualsWithDelta($qty, $written[$materialId] ?? 0.0, 0.001, "Списание материала #{$materialId}");
            $this->assertEqualsWithDelta(
                $stockBefore[$materialId] - $qty,
                (float) MaterialStock::query()->findOrFail($materialId)->quantity,
                0.001,
                "Остаток материала #{$materialId}",
            );
        }
        $this->assertSame(count($expected), $written->count(), 'Лишних списаний нет');

        // --- 7. Пока завод работает, сделку не сдвинуть ---------------------------
        try {
            $this->production->moveToStage($deal->refresh(), $this->sales('ready_to_ship'), $this->manager);
            $this->fail('Сделка обогнала завод');
        } catch (ProductionException) {
        }

        // --- 8. Цех: мастер режет, рабочий делает остальное без «Взять в работу» --
        $this->production->startStage($order, $this->master);
        $this->production->completeCurrentStage($order->refresh(), $this->master);
        $this->assertSame('welding', $order->refresh()->currentStage->code);

        while (! $order->refresh()->status_id->isClosed()) {
            $this->production->completeCurrentStage($order, $this->worker);
        }

        $order->refresh();
        $deal->refresh();

        $this->assertSame(DealStatus::Completed, $order->status_id);
        $this->assertSame(DealStatus::ReadyToShip, $deal->status_id);
        $this->assertSame('ready_to_ship', $deal->currentStage->code, 'Завод сам перевёл сделку на следующий этап');
        $this->assertNull($deal->activeProductionOrder(), 'Сделка снова свободна');

        // --- 9. Сдельная оплата: каждый этап ровно один раз, тому, кто его сделал --
        $stages = FactoryStage::query()->ofPipeline(PipelineType::Factory)->active()->get();
        $logs = ProductionLog::query()->where('deal_id', $order->id)->get();

        $this->assertSame($stages->count(), $logs->count(), 'Один лог на этап');
        $this->assertTrue($logs->every(fn (ProductionLog $l): bool => $l->status === ProductionStatus::Done));
        $this->assertEqualsWithDelta((float) $stages->sum('operation_cost'), (float) $logs->sum('payout'), 0.01);

        $cutting = (float) $stages->firstWhere('code', 'metal_cutting')->operation_cost;
        $this->assertEqualsWithDelta($cutting, $this->master->payoutBetween(now()->subDay(), now()->addDay()), 0.01);
        $this->assertEqualsWithDelta(
            (float) $stages->sum('operation_cost') - $cutting,
            $this->worker->payoutBetween(now()->subDay(), now()->addDay()),
            0.01,
            'Оплата за этапы, закрытые рабочим без «Взять в работу», идёт рабочему',
        );

        // --- 10. История: продажи, склад и завод в одной ленте --------------------
        $departments = $deal->events()->get()->pluck('department')->unique();
        foreach ([Department::Sales, Department::Warehouse, Department::Factory] as $department) {
            $this->assertTrue($departments->contains($department), "В истории нет отдела {$department->getLabel()}");
        }
        $this->assertSame($stages->count(), $deal->events()->where('type', DealEventType::ProductionStage->value)->count());
        $this->assertSame(1, $deal->events()->where('type', DealEventType::Payment->value)->count());

        // --- 11. Отгрузка и закрытие — только после полной оплаты -----------------
        $this->production->moveToStage($deal->refresh(), $this->sales('delivery'), $this->manager);

        try {
            $this->production->moveToStage($deal->refresh(), $this->sales('closed'), $this->manager);
            $this->fail('Сделку закрыли без полной оплаты');
        } catch (ProductionException $e) {
            $this->assertStringContainsString('оплачена полностью', $e->getMessage());
        }

        $deal->payments()->create(['amount' => $deal->remainingPayment(), 'method' => PaymentMethod::Cash, 'paid_at' => now(), 'receipt_path' => 'receipts/2.pdf']);
        $this->assertTrue($deal->refresh()->isPaidInFull());

        $this->production->moveToStage($deal->refresh(), $this->sales('closed'), $this->manager);
        $deal->refresh();

        $this->assertSame('closed', $deal->currentStage->code);
        $this->assertSame(DealStatus::Completed, $deal->status_id, 'Завершающий этап закрывает сделку');
        $this->assertFalse(Deal::query()->open()->whereKey($deal->id)->exists(), 'Закрытая сделка не считается открытой');

        // --- 12. Страница клиента говорит правду ---------------------------------
        $this->get(route('track.show', $deal->qr_code_hash))->assertOk()->assertSee(DealStatus::Completed->publicLabel());
    }

    public function test_cancelled_order_returns_exactly_what_was_written_off(): void
    {
        $deal = $this->readyForProduction();
        $before = MaterialStock::query()->pluck('quantity', 'id')->map(fn ($q): float => (float) $q);

        $this->production->moveToStage($deal, $this->sales('handed_to_production'), $this->manager);
        $order = $deal->refresh()->productionOrder;

        $this->production->startStage($order, $this->master);
        $this->production->completeCurrentStage($order, $this->master);

        $this->production->cancelProduction($order->refresh(), $this->manager, 'Клиент передумал');
        // Повторная отмена ничего не задваивает.
        $this->production->returnMaterials($order->refresh(), $this->manager);

        foreach ($before as $id => $qty) {
            $this->assertEqualsWithDelta($qty, (float) MaterialStock::query()->findOrFail($id)->quantity, 0.001, "Материал #{$id} вернулся не полностью");
        }

        $deal->refresh();
        $this->assertSame(DealStatus::Cancelled, $order->refresh()->status_id);
        $this->assertSame(DealStatus::InWork, $deal->status_id);
        $this->assertNull($deal->activeProductionOrder());

        // Сдельная оплата за уже сделанный раскрой у мастера остаётся.
        $this->assertGreaterThan(0, $this->master->payoutBetween(now()->subDay(), now()->addDay()));

        // Сделка сама вернулась на шаг назад, и повторная передача откроет новый наряд.
        $this->assertSame('contract', $deal->currentStage->code);
        $this->production->moveToStage($deal->refresh(), $this->sales('handed_to_production'), $this->manager);
        $this->assertNotSame($order->id, $deal->refresh()->productionOrder->id);
    }

    public function test_handoff_twice_creates_one_order(): void
    {
        $deal = $this->readyForProduction();
        $this->production->moveToStage($deal, $this->sales('handed_to_production'), $this->manager);

        $first = $deal->refresh()->productionOrder;
        $this->production->handOffToProduction($deal->refresh(), $this->manager);

        $this->assertSame(1, Deal::query()->factoryOrders()->where('parent_deal_id', $deal->id)->count());
        $this->assertSame($first->id, $deal->refresh()->productionOrder->id);
        $this->assertSame(1, StockMovement::query()->where('deal_id', $first->id)->where('material_stock_id', $this->stock('MET-15'))->count(), 'Материал списан один раз');
    }

    /** @return array<int, float> material_stock_id => количество */
    private function expectedMaterials(array $doors): array
    {
        $expected = [];

        foreach ($doors as $door) {
            $door->refresh();
            $area = $door->areaSqm();
            $perimeter = $door->perimeterMeters();

            foreach ($door->selectedCodes() as $category => $codes) {
                foreach ($codes as $code) {
                    $option = DoorOption::query()->where('category', $category)->where('code', $code)->first();

                    if (! $option?->material_stock_id) {
                        continue;
                    }

                    $amount = round($option->consumptionFor($area, $perimeter) * max(1, $door->quantity), 3);

                    if ($amount <= 0) {
                        continue;
                    }

                    $expected[$option->material_stock_id] = round(($expected[$option->material_stock_id] ?? 0) + $amount, 3);
                }
            }
        }

        return $expected;
    }

    private function newDeal(): Deal
    {
        return Deal::create([
            'title' => 'ЖК «Цикл», кв. 1',
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'client_address' => 'ул. Тестовая 1',
            'city' => 'Алматы',
            'status_id' => DealStatus::New,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => $this->sales('new')->id,
            'manager_id' => $this->manager->id,
            'due_date' => now()->addWeeks(3),
        ]);
    }

    private function readyForProduction(): Deal
    {
        $deal = $this->newDeal();
        $deal->update([
            'current_stage_id' => $this->sales('contract')->id,
            'status_id' => DealStatus::InWork,
            'measured_at' => now()->subDay(),
            'contract_number' => 'ДГ-2',
            'contract_date' => now(),
            'documents' => ['deals/contract.pdf'],
        ]);
        $this->door($deal, ['category' => 'premium', 'model' => 'lion', 'width' => 950, 'quantity' => 1,
            'metal_thickness' => 'metal_1_5', 'lock_system' => 'lock_kale']);
        $deal->payments()->create(['amount' => 50_000, 'method' => PaymentMethod::Kaspi, 'paid_at' => now(), 'receipt_path' => 'receipts/x.pdf']);

        return $deal->refresh();
    }

    /** @param array<string, mixed> $attributes */
    private function door(Deal $deal, array $attributes): DoorConfiguration
    {
        return DoorConfiguration::create([
            'deal_id' => $deal->id,
            'height' => 2050,
            'opening_side' => 'right',
            ...$attributes,
        ]);
    }

    private function sales(string $code): FactoryStage
    {
        return FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', $code)->firstOrFail();
    }

    private function stock(string $sku): int
    {
        return (int) MaterialStock::query()->where('sku', $sku)->value('id');
    }
}
