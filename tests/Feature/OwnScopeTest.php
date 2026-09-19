<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Enums\DealStatus;
use App\Enums\Permission;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Filament\Pages\FactoryKanban;
use App\Filament\Pages\OverdueDeals;
use App\Filament\Pages\SalesKanban;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Models\Deal;
use App\Models\DoorConfiguration;
use App\Models\FactoryStage;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\DoorProductionService;
use Database\Seeders\DoorOptionSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Уровень «только свои»: менеджер видит и двигает лишь свои сделки. */
class OwnScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $colleague;

    private Deal $mine;

    private Deal $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        AccessControl::flush();
        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);

        $this->manager = User::factory()->create(['role' => UserRole::Manager->value]);
        $this->colleague = User::factory()->create(['role' => UserRole::Manager->value]);

        $this->mine = $this->deal('Моя сделка', $this->manager);
        $this->foreign = $this->deal('Чужая сделка', $this->colleague);

        $this->actingAs($this->manager);
    }

    public function test_deal_list_and_search_show_only_own_deals(): void
    {
        $visible = DealResource::getEloquentQuery()->pluck('title');

        $this->assertTrue($visible->contains('Моя сделка'));
        $this->assertFalse($visible->contains('Чужая сделка'));

        $this->get('/admin/deals')->assertOk()->assertSee('Моя сделка')->assertDontSee('Чужая сделка');
    }

    public function test_foreign_deal_is_not_reachable_by_direct_link(): void
    {
        $this->get("/admin/deals/{$this->mine->id}/edit")->assertOk();
        // Чужая сделка не просто закрыта — её для менеджера не существует.
        $this->get("/admin/deals/{$this->foreign->id}/edit")->assertNotFound();

        $this->assertFalse($this->manager->can('view', $this->foreign));
        $this->assertFalse($this->manager->can('update', $this->foreign));
        $this->assertFalse($this->manager->can('move', $this->foreign));
    }

    public function test_sales_kanban_shows_only_own_cards_and_hides_the_manager_filter(): void
    {
        $board = Livewire::test(SalesKanban::class)->instance();
        $titles = $board->getStages()->flatMap(fn ($stage) => $stage->deals->pluck('title'));

        $this->assertTrue($titles->contains('Моя сделка'));
        $this->assertFalse($titles->contains('Чужая сделка'));
        $this->assertSame($this->manager->id, $board->ownerId());
        $this->assertSame([], $board->getManagerOptions(), 'Фильтр по менеджеру при «только своих» не нужен');

        $this->get('/admin/kanban/sales')->assertOk()->assertSee('Моя сделка')->assertDontSee('Чужая сделка');
    }

    public function test_column_total_is_hidden_from_the_manager_but_card_sums_are_not(): void
    {
        $board = Livewire::test(SalesKanban::class)->instance();

        $this->assertTrue($board->canSeeMoney(), 'Суммы своих карточек менеджер видит');
        $this->assertFalse($board->canSeeTotals(), 'Итог воронки — сводный показатель');

        $director = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->actingAs($director);
        $this->assertTrue(Livewire::test(SalesKanban::class)->instance()->canSeeTotals());
    }

    public function test_overdue_page_lists_only_own_deals(): void
    {
        $this->mine->forceFill(['due_date' => now()->subDays(5)])->saveQuietly();
        $this->foreign->forceFill(['due_date' => now()->subDays(5)])->saveQuietly();

        $this->get('/admin/overdue-deals')->assertOk()->assertSee('Моя сделка')->assertDontSee('Чужая сделка');
        $this->assertSame('1', OverdueDeals::getNavigationBadge());
    }

    public function test_list_summary_and_tabs_count_only_own_deals(): void
    {
        $summary = Livewire::test(ListDeals::class)->instance()->summary();

        $this->assertSame('1', $summary[0]['value'], 'В работе считается только своё');
    }

    public function test_an_unassigned_deal_stays_visible(): void
    {
        $orphan = $this->deal('Ничья сделка', null);

        $this->assertTrue(DealResource::getEloquentQuery()->pluck('title')->contains('Ничья сделка'));
        $this->assertTrue($this->manager->can('view', $orphan));
    }

    public function test_manager_sees_the_factory_board_read_only(): void
    {
        DoorConfiguration::create([
            'deal_id' => $this->mine->id,
            'category' => 'premium',
            'model' => 'lion',
            'height' => 2050,
            'width' => 950,
            'opening_side' => 'right',
            'quantity' => 1,
            'metal_thickness' => 'metal_1_5',
            'lock_system' => 'lock_kale',
        ]);

        app(DoorProductionService::class)->handOffToProduction($this->mine->refresh(), $this->manager);

        $this->get('/admin/kanban/factory')->assertOk();

        $board = Livewire::test(FactoryKanban::class)->instance();
        $order = $this->mine->refresh()->productionOrder;

        $this->assertNotNull($order);
        $this->assertFalse($this->manager->can('move', $order));
        $this->assertNull($board->ownerId(), 'На заводе «свои» не выделяются: там работает цех');
    }

    public function test_raising_the_level_to_full_opens_all_deals(): void
    {
        AccessControl::set(UserRole::Manager, Permission::WorkDeals, AccessLevel::Full);
        AccessControl::set(UserRole::Manager, Permission::WorkSalesKanban, AccessLevel::Full);

        $this->assertTrue(DealResource::getEloquentQuery()->pluck('title')->contains('Чужая сделка'));
        $this->assertTrue($this->manager->fresh()->can('view', $this->foreign));

        AccessControl::reset(UserRole::Manager);
    }

    private function deal(string $title, ?User $owner): Deal
    {
        return Deal::create([
            'title' => $title,
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => FactoryStage::firstOf(PipelineType::Sales)?->id,
            'manager_id' => $owner?->id,
            'total_price' => 500_000,
            'due_date' => now()->addWeeks(2),
        ]);
    }
}
