<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Filament\Pages\FactoryKanban;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Models\Deal;
use App\Models\DoorConfiguration;
use App\Models\FactoryStage;
use App\Models\User;
use App\Services\DoorProductionService;
use Database\Seeders\DoorOptionSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Filament\Actions\Action;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Последний этап цеха «ОТК и Упаковка» закрывается кнопкой «Готово ✓».
 *
 * Регрессия: стрелка «→» ведёт на соседний этап, а у последнего этапа соседа
 * нет — наряд с канбана завода и из карточки было не завершить, только
 * с планшета цеха. Сделка продаж при этом навсегда оставалась «ждёт завод».
 */
class FactoryFinishButtonTest extends TestCase
{
    use RefreshDatabase;

    private DoorProductionService $production;

    private User $master;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);
        $this->production = app(DoorProductionService::class);
        $this->master = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->actingAs($this->master);
    }

    public function test_factory_kanban_shows_finish_button_on_the_last_stage(): void
    {
        $this->orderOnLastStage();

        $this->get('/admin/kanban/factory')
            ->assertOk()
            ->assertSee('Готово ✓')
            ->assertSee("mountAction('completeStage'", false);
    }

    public function test_factory_kanban_does_not_show_finish_button_on_earlier_stages(): void
    {
        $this->orderInProduction();

        // Слова «Готово ✓» есть в подзаголовке страницы, поэтому смотрим на саму кнопку.
        $this->get('/admin/kanban/factory')->assertOk()->assertDontSee("mountAction('completeStage'", false);
    }

    public function test_kanban_finish_button_closes_the_order_and_frees_the_sales_deal(): void
    {
        $order = $this->orderOnLastStage();
        $deal = $order->parentDeal;

        Livewire::test(FactoryKanban::class)
            ->call('completeStage', $order->id)
            ->assertHasNoErrors();

        $order->refresh();
        $deal->refresh();

        $this->assertTrue($order->status_id->isClosed(), 'Наряд не закрылся');
        $this->assertNotNull($order->production_finished_at);
        $this->assertSame(DealStatus::ReadyToShip, $deal->status_id);
        $this->assertSame('ready_to_ship', $deal->currentStage->code);
        $this->assertNull($deal->activeProductionOrder());
    }

    public function test_kanban_finish_pays_the_workshop_person_who_closed_the_stage(): void
    {
        $order = $this->orderOnLastStage();
        $foreman = User::factory()->create(['role' => UserRole::Master->value]);
        $this->actingAs($foreman);

        Livewire::test(FactoryKanban::class)->call('completeStage', $order->id);

        $log = $order->productionLogs()->whereHas('stage', fn ($q) => $q->where('code', 'qc_packing'))->firstOrFail();

        $this->assertSame($foreman->id, $log->worker_id);
        $this->assertEqualsWithDelta(2000, (float) $log->payout, 0.01);
        $this->assertNotNull($log->finished_at);
    }

    /** Администратор дверь не упаковывал: этап закрыт, но оплата никому не начислена. */
    public function test_kanban_finish_by_admin_does_not_pay_the_admin(): void
    {
        $order = $this->orderOnLastStage();

        Livewire::test(FactoryKanban::class)->call('completeStage', $order->id);

        $log = $order->productionLogs()->whereHas('stage', fn ($q) => $q->where('code', 'qc_packing'))->firstOrFail();

        $this->assertNull($log->worker_id);
        $this->assertEqualsWithDelta(0, (float) $log->payout, 0.01);
        $this->assertTrue($order->refresh()->status_id->isClosed());
    }

    public function test_kanban_confirmation_modal_explains_what_happens(): void
    {
        $order = $this->orderOnLastStage();

        $component = Livewire::test(FactoryKanban::class)
            ->mountAction('completeStage', ['dealId' => $order->id])
            ->assertActionMounted('completeStage');

        // Модалка рендерится отдельной partial-областью Livewire и в html() теста
        // не попадает, поэтому тексты проверяем у самого действия.
        $action = $component->instance()->getMountedAction();
        $description = $this->modalDescription($action);

        $this->assertSame('Этап «ОТК и Упаковка» выполнен?', $action->getModalHeading());
        $this->assertStringContainsString($order->number, $description);
        $this->assertStringContainsString($order->parentDeal->number, $description);
        $this->assertStringContainsString('Готово к отгрузке', $description);

        $component->callMountedAction()->assertHasNoErrors();

        $this->assertTrue($order->refresh()->status_id->isClosed());
    }

    public function test_deal_card_confirmation_modal_closes_the_order(): void
    {
        $order = $this->orderOnLastStage();

        $component = Livewire::test(EditDeal::class, ['record' => $order->id])
            ->mountAction('completeStage')
            ->assertActionMounted('completeStage');

        $action = $component->instance()->getMountedAction();

        $this->assertSame('Этап «ОТК и Упаковка» выполнен?', $action->getModalHeading());
        $this->assertStringContainsString($order->parentDeal->number, $this->modalDescription($action));

        $component->callMountedAction()->assertHasNoErrors();

        $this->assertTrue($order->refresh()->status_id->isClosed());
    }

    public function test_kanban_finish_ignores_a_sales_deal(): void
    {
        $order = $this->orderInProduction();
        $deal = $order->parentDeal;

        Livewire::test(FactoryKanban::class)->call('completeStage', $deal->id);

        $this->assertSame('handed_to_production', $deal->refresh()->currentStage->code);
        $this->assertSame('metal_cutting', $order->refresh()->currentStage->code);
    }

    public function test_deal_card_shows_finish_button_for_the_order_on_the_last_stage(): void
    {
        $order = $this->orderOnLastStage();

        $this->get("/admin/deals/{$order->id}/edit")
            ->assertOk()
            ->assertSee('закрыть наряд')
            ->assertSee("mountAction('completeStage')", false);
    }

    public function test_deal_card_finish_button_closes_the_order(): void
    {
        $order = $this->orderOnLastStage();

        Livewire::test(EditDeal::class, ['record' => $order->id])
            ->call('completeStage')
            ->assertHasNoErrors();

        $this->assertTrue($order->refresh()->status_id->isClosed());
        $this->assertSame(DealStatus::ReadyToShip, $order->parentDeal->refresh()->status_id);
    }

    public function test_closed_order_card_says_so_instead_of_offering_the_button(): void
    {
        $order = $this->orderOnLastStage();
        $this->production->completeCurrentStage($order, $this->master);

        $this->get("/admin/deals/{$order->id}/edit")
            ->assertOk()
            ->assertSee('Наряд закрыт')
            ->assertDontSee('закрыть наряд');
    }

    private function modalDescription(Action $action): string
    {
        $description = $action->getModalDescription();

        return $description instanceof Htmlable ? $description->toHtml() : (string) $description;
    }

    protected function orderOnLastStage(): Deal
    {
        $order = $this->orderInProduction();

        while ($order->refresh()->currentStage->code !== 'qc_packing') {
            $this->production->completeCurrentStage($order, $this->master);
        }

        return $order->refresh();
    }

    protected function orderInProduction(): Deal
    {
        $deal = Deal::create([
            'title' => 'ЖК «Финиш», кв. 9',
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-09',
            'client_address' => 'ул. Тестовая 9',
            'city' => 'Алматы',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => $this->salesStage('contract')->id,
            'manager_id' => $this->master->id,
            'measured_at' => now()->subDay(),
            'due_date' => now()->addWeeks(2),
            'contract_number' => 'ДГ-ТЕСТ-9',
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

        $this->production->moveToStage($deal->refresh(), $this->salesStage('handed_to_production'), $this->master);

        return $deal->refresh()->productionOrder()->firstOrFail();
    }

    protected function salesStage(string $code): FactoryStage
    {
        return FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', $code)->firstOrFail();
    }
}
