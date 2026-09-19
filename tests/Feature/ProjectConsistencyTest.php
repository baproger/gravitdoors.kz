<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Exceptions\PipelineException;
use App\Exceptions\ProductionException;
use App\Filament\Pages\OverdueDeals;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\MaterialStocks\Pages\ManageMaterialStocks;
use App\Livewire\WorkshopScreen;
use App\Models\Deal;
use App\Models\DealPayment;
use App\Models\DoorConfiguration;
use App\Models\FactoryStage;
use App\Models\MaterialStock;
use App\Models\ProductionLog;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\DoorProductionService;
use App\Services\PipelineStageService;
use Database\Seeders\DoorOptionSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Противоречия между правилами README и кодом, найденные аудитом.
 * Каждый тест — одно правило, которое раньше обходилось.
 */
class ProjectConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private DoorProductionService $production;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);
        $this->production = app(DoorProductionService::class);
        $this->admin = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->actingAs($this->admin);
    }

    // ---- «Наряд по сделке создаётся один, списание не задваивается» -------------

    public function test_a_produced_deal_moved_back_and_forward_does_not_get_a_second_order(): void
    {
        $deal = $this->producedDeal();
        $stockBefore = MaterialStock::query()->pluck('quantity', 'id');

        $this->production->moveToStage($deal->refresh(), $this->sales('contract'), $this->admin);
        $this->production->moveToStage($deal->refresh(), $this->sales('handed_to_production'), $this->admin);

        $this->assertSame(1, Deal::query()->factoryOrders()->where('parent_deal_id', $deal->id)->count(), 'Появился второй наряд');
        $this->assertEquals($stockBefore->all(), MaterialStock::query()->pluck('quantity', 'id')->all(), 'Материалы списаны второй раз');
        $this->assertSame(DealStatus::ReadyToShip, $deal->refresh()->status_id, 'Двери уже сделаны — сделка готова к отгрузке');
    }

    // ---- Статус следует за этапом -------------------------------------------------

    public function test_status_returns_to_in_work_when_the_deal_is_moved_back_before_production(): void
    {
        $deal = $this->producedDeal();
        $this->assertSame(DealStatus::ReadyToShip, $deal->refresh()->status_id);

        $this->production->moveToStage($deal->refresh(), $this->sales('contract'), $this->admin);

        $this->assertSame(DealStatus::InWork, $deal->refresh()->status_id);
    }

    public function test_status_stays_ready_to_ship_past_production(): void
    {
        $deal = $this->producedDeal();

        $this->production->moveToStage($deal->refresh(), $this->sales('delivery'), $this->admin);

        $this->assertSame(DealStatus::ReadyToShip, $deal->refresh()->status_id);
    }

    public function test_status_field_in_the_card_is_read_only(): void
    {
        $deal = $this->readyDeal();

        Livewire::test(EditDeal::class, ['record' => $deal->id])
            ->fillForm(['status_id' => DealStatus::ReadyToShip->value])
            ->call('save');

        $this->assertSame(DealStatus::InWork, $deal->refresh()->status_id);
    }

    public function test_cancel_deal_action_closes_the_deal_and_its_order(): void
    {
        $deal = $this->dealInProduction();
        $order = $deal->refresh()->productionOrder;
        $stockBefore = MaterialStock::query()->pluck('quantity', 'id');

        Livewire::test(EditDeal::class, ['record' => $deal->id])
            ->callAction('cancelDeal', data: ['reason' => 'Клиент отказался'])
            ->assertHasNoErrors();

        $this->assertSame(DealStatus::Cancelled, $deal->refresh()->status_id);
        $this->assertSame(DealStatus::Cancelled, $order->refresh()->status_id);
        $this->assertNotEquals($stockBefore->all(), MaterialStock::query()->pluck('quantity', 'id')->all(), 'Материалы не вернулись');
        $this->assertTrue($deal->events()->where('type', 'cancelled')->exists());
    }

    // ---- Закрытые наряды неприкосновенны --------------------------------------------

    public function test_a_cancelled_order_cannot_be_completed_or_started(): void
    {
        $deal = $this->dealInProduction();
        $order = $deal->refresh()->productionOrder;
        $this->production->cancelProduction($order, $this->admin, 'Брак');

        $this->expectException(ProductionException::class);
        $this->production->completeCurrentStage($order->refresh(), $this->admin);
    }

    public function test_a_cancelled_order_cannot_be_cancelled_twice(): void
    {
        $deal = $this->dealInProduction();
        $order = $deal->refresh()->productionOrder;
        $this->production->cancelProduction($order, $this->admin, 'Брак');

        $this->expectException(ProductionException::class);
        $this->production->cancelProduction($order->refresh(), $this->admin, 'Ещё раз');
    }

    public function test_pushing_the_order_past_qc_from_the_stepper_pays_exactly_one_stage(): void
    {
        $stages = app(PipelineStageService::class);
        $extra = $stages->add(PipelineType::Factory, 'Отгрузка со склада', final: true);
        $deal = $this->dealInProduction();
        $order = $deal->refresh()->productionOrder;
        $master = User::factory()->create(['role' => UserRole::Master->value]);

        while ($order->refresh()->currentStage->code !== 'qc_packing') {
            $this->production->completeCurrentStage($order, $master);
        }

        $this->production->moveToStage($order->refresh(), $extra, $master);

        $order->refresh();
        $this->assertTrue($order->status_id->isClosed());
        $this->assertSame('qc_packing', $order->currentStage->code, 'Наряд не должен заходить на этап после ОТК');
        $this->assertSame(0, ProductionLog::query()->where('deal_id', $order->id)->where('stage_id', $extra->id)->count(), 'Пустой лог на лишнем этапе');
        $this->assertSame(DealStatus::ReadyToShip, $deal->refresh()->status_id);
    }

    // ---- Сдельная оплата ---------------------------------------------------------------

    public function test_shop_tablet_refuses_to_complete_without_a_worker(): void
    {
        $order = $this->dealInProduction()->refresh()->productionOrder;

        Livewire::test(WorkshopScreen::class)
            ->set('code', Setting::workshopCode())
            ->call('enter')
            ->call('complete', $order->id)
            ->assertSet('error', 'Сначала выберите, кто работает.');

        $this->assertSame('metal_cutting', $order->refresh()->currentStage->code);
    }

    public function test_manager_cannot_close_workshop_stages(): void
    {
        $order = $this->dealInProduction()->refresh()->productionOrder;
        $manager = User::factory()->create(['role' => UserRole::Manager->value]);
        $order->parentDeal->forceFill(['manager_id' => $manager->id])->saveQuietly();

        $this->assertFalse($manager->can('move', $order), 'Наряды закрывает цех, а не продажи');
        $this->assertTrue($manager->can('move', $order->parentDeal->refresh()), 'Свою сделку менеджер двигает');

        // Воронка завода менеджеру открыта на чтение: он следит за своим заказом,
        // но кнопок движения на карточках нет.
        $this->actingAs($manager);
        $this->get('/admin/kanban/factory')
            ->assertOk()
            ->assertDontSee('wire:click="moveDeal(', false)
            ->assertDontSee("mountAction('completeStage'", false);
    }

    public function test_worker_cannot_close_a_stage_from_the_deal_list(): void
    {
        $order = $this->dealInProduction()->refresh()->productionOrder;
        $worker = User::factory()->create(['role' => UserRole::Worker->value]);
        $this->actingAs($worker);

        // Неавторизованное действие Filament прячет, а вызвать его напрямую нельзя.
        Livewire::test(ListDeals::class)
            ->assertActionHidden(TestAction::make('completeStage')->table($order));

        $this->assertFalse($worker->can('move', $order));
        $this->assertSame('metal_cutting', $order->refresh()->currentStage->code);
    }

    // ---- Удаление --------------------------------------------------------------------

    public function test_a_deal_with_a_live_order_cannot_be_deleted(): void
    {
        $deal = $this->dealInProduction();
        $order = $deal->refresh()->productionOrder;

        // Администратор проходит политику через Gate::before, поэтому правило — в модели и в действии.
        $this->assertFalse($deal->canBeDeleted());
        $this->assertFalse($order->canBeDeleted(), 'Живой наряд не удаляется, а отменяется');
        $this->assertFalse($deal->delete(), 'Наблюдатель должен остановить удаление');
        $this->assertNull($deal->refresh()->deleted_at);

        Livewire::test(EditDeal::class, ['record' => $deal->id])->assertActionHidden('delete');

        $this->production->cancelProduction($order, $this->admin, 'Отказ');

        $this->assertTrue($deal->refresh()->canBeDeleted());
        $this->assertTrue($order->refresh()->canBeDeleted());
        Livewire::test(EditDeal::class, ['record' => $deal->id])->assertActionVisible('delete');
    }

    public function test_a_material_with_movements_cannot_be_deleted(): void
    {
        $this->dealInProduction();
        $used = MaterialStock::query()->whereIn('id', StockMovement::query()->pluck('material_stock_id'))->firstOrFail();
        $unused = MaterialStock::query()->whereDoesntHave('movements')->whereDoesntHave('doorOptions')->first()
            ?? MaterialStock::create(['sku' => 'EMPTY-1', 'name' => 'Пустой материал', 'unit' => 'pcs', 'quantity' => 0, 'min_limit' => 0, 'price_per_unit' => 0]);

        $this->assertFalse($used->canBeDeleted());
        $this->assertTrue($unused->canBeDeleted());

        Livewire::test(ManageMaterialStocks::class)
            ->assertActionHidden(TestAction::make('delete')->table($used))
            ->assertActionVisible(TestAction::make('delete')->table($unused));
    }

    // ---- Платежи -----------------------------------------------------------------------

    public function test_payments_cannot_exceed_the_deal_total_on_the_server(): void
    {
        $deal = $this->readyDeal();
        $this->assertGreaterThan(0, (float) $deal->total_price);

        $this->expectException(ValidationException::class);

        DealPayment::create([
            'deal_id' => $deal->id,
            'amount' => (float) $deal->total_price + 1,
            'method' => 'cash',
            'paid_at' => now(),
            'receipt_path' => 'receipts/x.jpg',
        ]);
    }

    // ---- Роли -----------------------------------------------------------------------

    public function test_surveyor_sees_neither_deals_nor_warehouse_nor_overdue(): void
    {
        $this->readyDeal();
        $this->actingAs(User::factory()->create(['role' => UserRole::Surveyor->value]));

        $this->get('/admin/deals')->assertForbidden();
        $this->get('/admin/material-stocks')->assertForbidden();
        $this->get('/admin/overdue-deals')->assertForbidden();
        $this->get('/admin/kanban/factory')->assertForbidden();
        $this->get('/admin')->assertOk();
    }

    public function test_survey_notification_has_no_link_to_a_forbidden_page(): void
    {
        $surveyor = User::factory()->create(['role' => UserRole::Surveyor->value]);
        $deal = $this->readyDeal();

        $deal->update(['measured_at' => now()->addDay()]);

        $notification = $surveyor->notifications()->latest()->first();

        $this->assertNotNull($notification, 'Замерщик не получил уведомление');
        $this->assertSame([], $notification->data['actions'] ?? [], 'Ссылка вела бы на 403');
        $this->assertStringContainsString($deal->number, $notification->data['body']);
    }

    public function test_worker_sees_the_factory_board_without_buttons_that_would_fail(): void
    {
        $this->dealInProduction();
        $this->actingAs(User::factory()->create(['role' => UserRole::Worker->value]));

        // Кнопок движения нет, но карточку наряда рабочий открывает — на чтение.
        $this->get('/admin/kanban/factory')
            ->assertOk()
            ->assertDontSee('wire:click="moveDeal(', false)
            ->assertDontSee("mountAction('completeStage'", false)
            ->assertSee('>Открыть<', false);

        $this->actingAs(User::factory()->create(['role' => UserRole::Master->value]));

        $this->get('/admin/kanban/factory')->assertOk()->assertSee('wire:click="moveDeal(', false);
    }

    public function test_overdue_page_does_not_count_an_order_twice_for_the_office(): void
    {
        $deal = $this->dealInProduction();
        $deal->forceFill(['due_date' => now()->subDays(2)])->saveQuietly();
        $deal->productionOrder->forceFill(['due_date' => now()->subDays(2)])->saveQuietly();

        $this->assertSame('1', OverdueDeals::getNavigationBadge());

        $this->get('/admin/overdue-deals')->assertOk()->assertSee($deal->number)->assertDontSee($deal->productionOrder->number);
    }

    public function test_global_search_covers_number_client_and_phone(): void
    {
        $this->assertSame(
            ['number', 'title', 'client_name', 'client_phone'],
            DealResource::getGloballySearchableAttributes(),
        );
    }

    public function test_total_price_is_not_taken_from_the_form(): void
    {
        $deal = $this->readyDeal();
        $calculated = (float) $deal->total_price;

        Livewire::test(EditDeal::class, ['record' => $deal->id])
            ->fillForm(['total_price' => 1])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsWithDelta($calculated, (float) $deal->refresh()->total_price, 0.01);
    }

    // ---- Конструктор воронок --------------------------------------------------------

    public function test_automation_cannot_be_switched_off_only_moved(): void
    {
        $stages = app(PipelineStageService::class);

        $this->expectException(PipelineException::class);
        $stages->setAutomation($this->sales('handed_to_production'), false);
    }

    public function test_deleting_a_stage_cannot_move_deals_to_a_final_stage(): void
    {
        $stages = app(PipelineStageService::class);
        $this->readyDeal();

        $this->expectException(PipelineException::class);
        $stages->delete($this->sales('contract'), $this->sales('closed'), $this->admin);
    }

    // ---- Ежедневная проверка считает просрочку так же, как экран -------------------

    public function test_daily_check_uses_the_same_overdue_rule_as_the_screen(): void
    {
        $order = $this->dealInProduction()->refresh()->productionOrder;

        // Раскрой — норматив 2 ч. На этапе 3 ч по журналу заходов, но текущий лог
        // цеха открыт только что: старая проверка по логу не увидела бы просрочку.
        $order->forceFill(['stage_entered_at' => now()->subHours(3)])->saveQuietly();
        $order->stageVisits()->whereNull('left_at')->update(['entered_at' => now()->subHours(3)]);
        ProductionLog::query()->where('deal_id', $order->id)->whereNull('finished_at')->update(['started_at' => now()]);

        $this->assertTrue($order->refresh()->isStageOverdue());

        $this->artisan('gravit:daily-check', ['--dry' => true])
            ->expectsTable(['Проверка', 'Найдено'], [
                ['Материалы ниже минимума', MaterialStock::query()->where('is_active', true)->belowLimit()->count()],
                ['Этапы дольше норматива', 1],
            ]);
    }

    // ---- helpers ------------------------------------------------------------------------

    private function producedDeal(): Deal
    {
        $deal = $this->dealInProduction();
        $order = $deal->refresh()->productionOrder;
        $master = User::factory()->create(['role' => UserRole::Master->value]);

        while (! $order->refresh()->status_id->isClosed()) {
            $this->production->completeCurrentStage($order, $master);
        }

        return $deal->refresh();
    }

    private function dealInProduction(): Deal
    {
        $deal = $this->readyDeal();
        $this->production->moveToStage($deal, $this->sales('handed_to_production'), $this->admin);

        return $deal->refresh();
    }

    private function readyDeal(): Deal
    {
        $deal = Deal::create([
            'title' => 'ЖК «Аудит», кв. 1',
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-01',
            'client_address' => 'ул. Тестовая 1',
            'city' => 'Алматы',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => $this->sales('contract')->id,
            'manager_id' => $this->admin->id,
            'measured_at' => now()->subDay(),
            'due_date' => now()->addWeeks(2),
            'contract_number' => 'ДГ-АУДИТ-1',
            'contract_date' => now()->subDays(2),
            'documents' => ['deals/contract.pdf'],
            'prepayment' => 50_000,
        ]);

        DoorConfiguration::create([
            'deal_id' => $deal->id,
            'category' => 'premium',
            'model' => 'lion',
            'height' => 2050,
            'width' => 950,
            'opening_side' => 'right',
            'quantity' => 1,
            'metal_thickness' => 'metal_1_5',
            'lock_system' => 'lock_kale',
        ]);

        $this->production->syncPricing($deal);

        return $deal->refresh();
    }

    private function sales(string $code): FactoryStage
    {
        return FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', $code)->firstOrFail();
    }

    private function factory(string $code): FactoryStage
    {
        return FactoryStage::query()->ofPipeline(PipelineType::Factory)->where('code', $code)->firstOrFail();
    }
}
