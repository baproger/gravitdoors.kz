<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Filament\Pages\DoorCalculator;
use App\Filament\Pages\FactoryKanban;
use App\Filament\Pages\SalesKanban;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Deals\RelationManagers\ProductionLogsRelationManager;
use App\Models\Deal;
use App\Models\DoorConfiguration;
use App\Models\FactoryStage;
use App\Models\User;
use App\Services\DoorProductionService;
use Database\Seeders\DoorOptionSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Каждый экран панели должен открываться — иначе ошибку видит уже пользователь. */
class AdminPanelSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);

        $this->admin = User::factory()->create(['role' => UserRole::Admin->value, 'is_active' => true]);
        $this->actingAs($this->admin);
    }

    /** @return list<array{string}> */
    public static function panelPages(): array
    {
        return [
            'дашборд' => ['/admin'],
            'калькулятор' => ['/admin/calculator'],
            'канбан продаж' => ['/admin/kanban/sales'],
            'канбан завода' => ['/admin/kanban/factory'],
            'сделки' => ['/admin/deals'],
            'создание сделки' => ['/admin/deals/create'],
            'этапы воронок' => ['/admin/factory-stages'],
            'прайс конфигуратора' => ['/admin/door-options'],
            'склад' => ['/admin/material-stocks'],
            'сотрудники' => ['/admin/users'],
            'зарплата цеха' => ['/admin/payroll'],
            'движения склада' => ['/admin/stock-movements'],
            'экран цеха' => ['/admin/workshop-access'],
        ];
    }

    #[DataProvider('panelPages')]
    public function test_panel_page_opens(string $url): void
    {
        $this->get($url)->assertOk();
    }

    public function test_deal_edit_page_opens(): void
    {
        $deal = $this->makeDeal();

        $this->get("/admin/deals/{$deal->id}/edit")->assertOk();
    }

    /**
     * Регрессия: состояние воронки приходит в форму enum'ом, а сравнивалось
     * со строкой — из-за этого секция позиций молча пропадала при редактировании.
     */
    public function test_deal_edit_page_shows_door_positions(): void
    {
        $deal = $this->makeDeal();

        $this->get("/admin/deals/{$deal->id}/edit")
            ->assertOk()
            ->assertSee('Толщина металла')
            ->assertSee('Тип клиента');
    }

    public function test_deal_create_page_shows_door_positions(): void
    {
        $this->get('/admin/deals/create')
            ->assertOk()
            ->assertSee('Толщина металла');
    }

    /** У наряда завода своих позиций нет — секция не должна показываться. */
    public function test_production_order_has_no_positions_section(): void
    {
        $deal = $this->makeDeal();
        $order = app(DoorProductionService::class)->handOffToProduction($deal);

        $this->get("/admin/deals/{$order->id}/edit")
            ->assertOk()
            ->assertDontSee('Толщина металла');
    }

    /**
     * Блок тайминга подгружается лениво, поэтому его нет в первом HTML —
     * проверяем само правило видимости, а не разметку.
     */
    public function test_timing_block_belongs_to_the_production_order_only(): void
    {
        $deal = $this->makeDeal();
        $order = app(DoorProductionService::class)->handOffToProduction($deal);

        $this->assertTrue(ProductionLogsRelationManager::canViewForRecord($order, EditDeal::class));
        $this->assertFalse(ProductionLogsRelationManager::canViewForRecord($deal, EditDeal::class));
    }

    public function test_inactive_user_cannot_access_panel(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => false]));

        $this->get('/admin')->assertForbidden();
    }

    public function test_kanban_shows_stages_and_deals(): void
    {
        $deal = $this->makeDeal();

        Livewire::test(SalesKanban::class)
            ->assertSee('Передано в производство')
            ->assertSee($deal->number);
    }

    public function test_kanban_search_filters_cards(): void
    {
        $deal = $this->makeDeal();

        Livewire::test(SalesKanban::class)
            ->set('search', 'несуществующий клиент')
            ->assertDontSee($deal->number);
    }

    public function test_dragging_card_to_production_stage_runs_automation(): void
    {
        $deal = $this->makeDeal();
        $target = FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', 'handed_to_production')->firstOrFail();

        Livewire::test(SalesKanban::class)
            ->call('moveDeal', $deal->id, $target->id)
            ->assertHasNoErrors();

        $this->assertNotNull($deal->refresh()->productionOrder, 'Перетаскивание не создало наряд');
        $this->assertSame(DealStatus::HandedToProduction, $deal->status_id);
    }

    public function test_dragging_card_into_foreign_pipeline_is_rejected(): void
    {
        $deal = $this->makeDeal();
        $factoryStage = FactoryStage::query()->ofPipeline(PipelineType::Factory)->where('code', 'welding')->firstOrFail();

        Livewire::test(SalesKanban::class)
            ->call('moveDeal', $deal->id, $factoryStage->id)
            ->assertHasNoErrors();

        $this->assertSame($this->stage('contract')->id, $deal->refresh()->current_stage_id, 'Сделка не должна была переехать');
    }

    public function test_factory_kanban_shows_only_production_orders(): void
    {
        $deal = $this->makeDeal();
        $order = app(DoorProductionService::class)->handOffToProduction($deal);

        Livewire::test(FactoryKanban::class)
            ->assertSee($order->number)
            ->assertDontSee($deal->number);
    }

    public function test_calculator_recalculates_on_state_change(): void
    {
        Livewire::test(DoorCalculator::class)
            ->set('data.height', 2000)
            ->set('data.width', 1000)
            ->set('data.metal_thickness', 'metal_1_5')
            ->set('data.lock_system', 'lock_kale')
            ->assertOk()
            ->assertSee('Металл 1.5 мм');
    }

    private function makeDeal(): Deal
    {
        $deal = Deal::create([
            'title' => 'Смоук-тест сделка',
            'client_name' => 'Клиент Смоук',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => $this->stage('contract')->id,
            'manager_id' => $this->admin->id,
        ]);

        DoorConfiguration::create([
            'deal_id' => $deal->id,
            'height' => 2050,
            'width' => 950,
            'opening_side' => 'right',
            'quantity' => 1,
            'metal_thickness' => 'metal_1_5',
            'lock_system' => 'lock_kale',
        ]);

        return $deal->refresh();
    }

    private function stage(string $code): FactoryStage
    {
        return FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', $code)->firstOrFail();
    }
}
