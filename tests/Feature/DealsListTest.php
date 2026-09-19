<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Models\Deal;
use App\Models\DoorConfiguration;
use App\Models\FactoryStage;
use App\Models\User;
use App\Services\DoorProductionService;
use App\Support\Money;
use Database\Seeders\DoorOptionSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Список сделок: сводка сверху и то, что видно в каждой строке. */
class DealsListTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);
        $this->manager = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->actingAs($this->manager);
    }

    public function test_summary_counts_work_overdue_factory_and_money_due(): void
    {
        $overdue = $this->deal('Просроченная', ['due_date' => now()->subDays(3), 'total_price' => 200_000, 'prepayment' => 50_000]);
        $inProduction = $this->deal('На заводе', ['total_price' => 300_000, 'prepayment' => 300_000]);
        app(DoorProductionService::class)->moveToStage($inProduction, $this->stage('handed_to_production'), $this->manager);
        $this->deal('Отменённая', ['status_id' => DealStatus::Cancelled, 'due_date' => now()->subDays(10), 'total_price' => 999_000]);

        $tiles = collect(Livewire::test(ListDeals::class)->instance()->summary())->keyBy('label');

        $this->assertSame('2', $tiles['В работе']['value']);
        $this->assertSame('1', $tiles['Ждут завод']['value']);
        $this->assertSame('1', $tiles['Просрочено']['value']);
        $this->assertTrue($tiles['Просрочено']['alert']);
        // Отменённая сделка и полностью оплаченная в остаток не входят.
        $this->assertSame(Money::format(150_000), $tiles['Ожидаем оплату']['value']);
        $this->assertNotNull($overdue);
    }

    public function test_row_says_the_deal_waits_for_the_factory(): void
    {
        $deal = $this->deal('Дверь в цеху');
        app(DoorProductionService::class)->moveToStage($deal, $this->stage('handed_to_production'), $this->manager);

        $this->get('/admin/deals')->assertOk()->assertSee('ждёт завод');
    }

    public function test_overdue_open_deal_is_labelled_but_a_closed_one_is_not(): void
    {
        $this->deal('Горит срок', ['due_date' => now()->subDays(2)]);
        $this->deal('Закрытая давно', ['due_date' => now()->subDays(40), 'status_id' => DealStatus::Completed]);

        $this->get('/admin/deals')
            ->assertOk()
            ->assertSee('просрочено на 2 дн.')
            ->assertDontSee('просрочено на 40 дн.');
    }

    public function test_search_finds_a_deal_by_phone(): void
    {
        $wanted = $this->deal('Нужный клиент', ['client_phone' => '+7 (777) 123-45-67']);
        $other = $this->deal('Другой клиент', ['client_phone' => '+7 (701) 000-00-00']);

        Livewire::test(ListDeals::class)
            ->searchTable('123-45-67')
            ->assertCanSeeTableRecords([$wanted])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_margin_is_not_shown_in_the_list(): void
    {
        $this->deal('Сделка с маржой', ['total_price' => 300_000, 'cost_price' => 100_000]);

        $this->get('/admin/deals')->assertOk()->assertDontSee('Маржа')->assertDontSee('66.67 %');
    }

    public function test_workshop_does_not_see_the_money_summary(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Worker->value]));

        $this->get('/admin/deals')->assertOk()->assertDontSee('Ожидаем оплату');
    }

    /** @param array<string, mixed> $attributes */
    private function deal(string $title, array $attributes = []): Deal
    {
        $deal = Deal::create([
            'title' => $title,
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'client_address' => 'ул. Тестовая 1',
            'city' => 'Алматы',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => $this->stage('contract')->id,
            'manager_id' => $this->manager->id,
            'measured_at' => now()->subDay(),
            'due_date' => now()->addWeeks(2),
            'contract_number' => 'ДГ-'.uniqid(),
            'contract_date' => now()->subDays(2),
            'documents' => ['deals/contract.pdf'],
            'prepayment' => 50_000,
            ...$attributes,
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

        return $deal->refresh();
    }

    /** Счётчик вкладки и её содержимое должны сходиться: закрытые — только в «Все». */
    public function test_working_tabs_show_open_records_and_closed_go_to_the_all_tab(): void
    {
        $open = $this->deal('Открытая сделка');
        $closed = $this->deal('Закрытая сделка');
        $closed->forceFill(['status_id' => DealStatus::Completed])->saveQuietly();

        Livewire::test(ListDeals::class)
            ->set('activeTab', 'sales')
            ->assertCanSeeTableRecords([$open])
            ->assertCanNotSeeTableRecords([$closed])
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$open, $closed]);
    }

    /**
     * Законченный наряд не остаётся второй строкой рядом со своей сделкой.
     *
     * Владелец увидел «Закрытые 1, Все 2» и не нашёл вторую запись ни в одной
     * вкладке: наряд стоял завершённым на этапе «Монтаж» и попадал только в
     * «Все». Для того, кто видит сделку, законченный наряд — её копия.
     */
    public function test_finished_factory_order_does_not_double_its_deal(): void
    {
        $deal = $this->deal('Асылбек Шымкент');
        app(DoorProductionService::class)->moveToStage($deal, $this->stage('handed_to_production'), $this->manager);
        $order = $deal->refresh()->productionOrder;
        $this->assertNotNull($order);

        // Пока цех работает — своя строка нужна: это отдельная работа.
        Livewire::test(ListDeals::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$deal, $order]);

        $order->forceFill(['status_id' => DealStatus::Completed])->saveQuietly();
        $deal->forceFill(['status_id' => DealStatus::Completed])->saveQuietly();

        $page = Livewire::test(ListDeals::class)->set('activeTab', 'all');
        $page->assertCanSeeTableRecords([$deal])->assertCanNotSeeTableRecords([$order]);

        // Счётчик вкладки считается своим запросом — он должен сойтись с таблицей.
        $tabs = $page->instance()->getTabs();
        $this->assertSame('1', (string) $tabs['all']->getBadge(), 'Во вкладке «Все» стояло 2, а строка была одна');
        $this->assertSame((string) $tabs['closed']->getBadge(), (string) $tabs['all']->getBadge());
    }

    /** У мастера цеха воронки продаж нет: для него наряд — единственная запись о заказе. */
    public function test_the_shop_keeps_seeing_its_finished_orders(): void
    {
        $deal = $this->deal('Асылбек Шымкент');
        app(DoorProductionService::class)->moveToStage($deal, $this->stage('handed_to_production'), $this->manager);
        $order = $deal->refresh()->productionOrder;
        $order?->forceFill(['status_id' => DealStatus::Completed])->saveQuietly();

        $master = User::factory()->create(['role' => UserRole::Master->value]);

        Livewire::test(ListDeals::class)->actingAs($master);
        $this->actingAs($master);

        Livewire::test(ListDeals::class)->assertCanSeeTableRecords([$order]);
    }

    /**
     * У наряда своих платежей нет — и он не выдаёт чужие за свои.
     *
     * Наряд копирует сумму сделки, но не предоплату, поэтому в списке стояло
     * «остаток 120 900 ₸» рядом со сделкой, где было «оплачено»: две
     * взаимоисключающие строки про одни и те же деньги.
     */
    public function test_factory_order_shows_no_payment_state(): void
    {
        $deal = $this->deal('Асылбек Шымкент', ['total_price' => 120_900, 'prepayment' => 120_900]);
        app(DoorProductionService::class)->moveToStage($deal, $this->stage('handed_to_production'), $this->manager);
        $order = $deal->refresh()->productionOrder;
        $this->assertNotNull($order);
        $this->assertSame(0.0, (float) $order->prepayment, 'Предоплата в наряд не копируется — отсюда и ложный остаток');

        Livewire::test(ListDeals::class)
            ->set('activeTab', 'all')
            ->assertTableColumnStateSet('remaining', 'оплачено', $deal)
            ->assertTableColumnStateSet('remaining', null, $order);
    }

    private function stage(string $code): FactoryStage
    {
        return FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', $code)->firstOrFail();
    }
}
