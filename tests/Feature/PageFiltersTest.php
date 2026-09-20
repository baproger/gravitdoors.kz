<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealSource;
use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\SalarySheetStatus;
use App\Enums\UserRole;
use App\Filament\Pages\FactoryKanban;
use App\Filament\Pages\FinanceOverview;
use App\Filament\Pages\Invoices;
use App\Filament\Pages\OverdueDeals;
use App\Filament\Pages\Payroll;
use App\Filament\Pages\SalarySheets;
use App\Filament\Pages\SalesKanban;
use App\Filament\Resources\MaterialStocks\Pages\ManageMaterialStocks;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\MaterialStock;
use App\Models\SalarySheet;
use App\Models\User;
use App\Services\DoorProductionService;
use App\Support\BoardFilter;
use Database\Seeders\DoorOptionSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Фильтры на рабочих страницах должны отбирать, а не просто рисоваться.
 *
 * Владелец попросил «чтобы через фильтр можно было найти то, что искал»: на
 * воронках, в просроченных и на складе. Тесты проверяют именно отбор — что
 * нужная запись остаётся, а лишняя уходит, — и что счётчик в шапке колонки
 * сходится с числом карточек под ним.
 */
class PageFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);
        $this->admin = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->actingAs($this->admin);
    }

    public function test_board_filters_by_city_source_and_due_date(): void
    {
        $almaty = $this->deal('Дверь в Алматы', ['city' => 'Алматы', 'source' => DealSource::cases()[0]->value, 'due_date' => now()->addDays(3)]);
        $astana = $this->deal('Дверь в Астане', ['city' => 'Астана', 'due_date' => now()->addDays(30)]);

        $this->assertBoardHas([$almaty], new BoardFilter(city: 'Алматы'));
        $this->assertBoardHas([$almaty], new BoardFilter(source: DealSource::cases()[0]->value));
        $this->assertBoardHas([$almaty], new BoardFilter(dueUntil: now()->addDays(7)->toDateString()));
        $this->assertBoardHas([$astana], new BoardFilter(dueFrom: now()->addDays(10)->toDateString()));
    }

    public function test_board_finds_only_overdue_and_only_unpaid(): void
    {
        $late = $this->deal('Просроченная', ['due_date' => now()->subDays(2), 'total_price' => 100, 'prepayment' => 0]);
        $paid = $this->deal('В срок и оплачена', ['due_date' => now()->addDays(5), 'total_price' => 100, 'prepayment' => 100]);

        $this->assertBoardHas([$late], new BoardFilter(overdueOnly: true));
        $this->assertBoardHas([$late], new BoardFilter(payment: 'due'));
        $this->assertBoardHas([$paid], new BoardFilter(payment: 'paid'));
    }

    /**
     * Счётчик в шапке колонки и карточки под ним считаются разными запросами.
     *
     * Если условие забыть в одном из них, колонка скажет «2», а карточка будет
     * одна — и доверия к доске не останется.
     */
    public function test_column_counter_matches_the_cards_under_it(): void
    {
        $this->deal('Алматы', ['city' => 'Алматы']);
        $this->deal('Астана', ['city' => 'Астана']);
        $this->deal('Шымкент', ['city' => 'Шымкент']);

        $column = app(DoorProductionService::class)
            ->board(PipelineType::Sales, new BoardFilter(city: 'Алматы'))
            ->firstWhere('code', 'new');

        $this->assertSame(1, $column->deals_count);
        $this->assertCount(1, $column->deals);
    }

    /**
     * Наряд не копирует город у сделки, но искать его по городу нужно.
     *
     * Без проверки через родителя фильтр на воронке цеха всегда давал бы пустой
     * экран — и выглядело бы это как поломка, а не как «ничего не найдено».
     */
    public function test_factory_board_finds_orders_by_the_city_of_their_deal(): void
    {
        $deal = $this->deal('Сделка в Таразе', ['city' => 'Тараз']);
        $order = $this->order($deal);

        $this->assertNull($order->city, 'Наряд действительно не хранит город — иначе тест проверял бы не то');

        $found = app(DoorProductionService::class)
            ->board(PipelineType::Factory, new BoardFilter(city: 'Тараз'))
            ->flatMap(fn (FactoryStage $stage) => $stage->deals)
            ->pluck('id');

        $this->assertTrue($found->contains($order->id));
    }

    public function test_resetting_filters_brings_every_card_back(): void
    {
        $this->deal('Алматы', ['city' => 'Алматы']);
        $this->deal('Астана', ['city' => 'Астана']);

        $page = Livewire::test(SalesKanban::class)
            ->set('city', 'Алматы')
            ->set('overdueOnly', true);

        $this->assertSame(2, $page->instance()->criteria()->activeCount());

        $page->call('resetFilters');

        $this->assertSame(0, $page->instance()->criteria()->activeCount());
        $this->assertNull($page->instance()->city);
        $this->assertFalse($page->instance()->overdueOnly);
    }

    /** Выбранное видно строкой над доской: полупустой экран должен объяснять себя. */
    public function test_board_says_which_filters_are_on(): void
    {
        $labels = (new BoardFilter(city: 'Алматы', overdueOnly: true, payment: 'due'))->labels();

        $this->assertContains('Алматы', $labels);
        $this->assertContains('только просроченные', $labels);
        $this->assertContains('есть остаток', $labels);
    }

    /** Развёрнутая панель — отдельная ветка разметки, её тоже надо отрисовать. */
    public function test_both_boards_render_the_open_filter_panel(): void
    {
        foreach ([SalesKanban::class, FactoryKanban::class] as $board) {
            Livewire::test($board)
                ->set('filtersOpen', true)
                ->set('overdueOnly', true)
                ->set('city', 'Алматы')
                ->assertOk()
                ->assertSee('Только просроченные')
                ->assertSee('Срок сдачи с')
                ->assertSee('Показаны только:');
        }
    }

    public function test_overdue_page_filters_by_manager(): void
    {
        $mine = User::factory()->create(['role' => UserRole::Manager->value]);
        $theirs = User::factory()->create(['role' => UserRole::Manager->value]);

        $ours = $this->deal('Моя просроченная', ['due_date' => now()->subDays(3), 'manager_id' => $mine->id]);
        $other = $this->deal('Чужая просроченная', ['due_date' => now()->subDays(3), 'manager_id' => $theirs->id]);

        Livewire::test(OverdueDeals::class)
            ->assertCanSeeTableRecords([$ours, $other])
            ->filterTable('manager_id', $mine->id)
            ->assertCanSeeTableRecords([$ours])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_warehouse_filters_find_what_ran_out(): void
    {
        $empty = MaterialStock::query()->firstOrFail();
        $empty->forceFill(['quantity' => 0])->save();

        $full = MaterialStock::query()->where('id', '!=', $empty->id)->firstOrFail();
        $full->forceFill(['quantity' => 500, 'min_limit' => 1])->save();

        Livewire::test(ManageMaterialStocks::class)
            ->filterTable('out_of_stock', true)
            ->assertCanSeeTableRecords([$empty])
            ->assertCanNotSeeTableRecords([$full]);
    }

    public function test_warehouse_finds_materials_no_price_list_uses(): void
    {
        $used = MaterialStock::query()->whereHas('doorOptions')->firstOrFail();
        $unused = MaterialStock::query()->whereDoesntHave('doorOptions')->first()
            ?? tap($used->replicate(), function (MaterialStock $copy): void {
                $copy->name = 'Ничем не используется';
                $copy->sku = 'UNUSED-1';
                $copy->save();
            });

        Livewire::test(ManageMaterialStocks::class)
            ->filterTable('unused', true)
            ->assertCanSeeTableRecords([$unused])
            ->assertCanNotSeeTableRecords([$used]);
    }

    /** Бухгалтеру в конце месяца важнее всего: кому ещё не выплатили. */
    public function test_salary_sheets_filter_by_status_and_unpaid(): void
    {
        $month = now()->format('Y-m');
        $paid = $this->sheet($month, ['total' => 100_000, 'paid_amount' => 100_000, 'status' => SalarySheetStatus::Paid]);
        $owed = $this->sheet($month, ['total' => 80_000, 'paid_amount' => 20_000, 'status' => SalarySheetStatus::Approved]);

        Livewire::test(SalarySheets::class)
            ->set('month', $month)
            ->assertCanSeeTableRecords([$paid, $owed])
            ->filterTable('unpaid', true)
            ->assertCanSeeTableRecords([$owed])
            ->assertCanNotSeeTableRecords([$paid]);
    }

    public function test_salary_sheets_filter_by_position(): void
    {
        $month = now()->format('Y-m');
        $worker = $this->sheet($month, [], UserRole::Worker);
        $manager = $this->sheet($month, [], UserRole::Manager);

        Livewire::test(SalarySheets::class)
            ->set('month', $month)
            ->filterTable('role', [UserRole::Worker->value])
            ->assertCanSeeTableRecords([$worker])
            ->assertCanNotSeeTableRecords([$manager]);
    }

    /** Зарплата цеха — своя страница, фильтр сужает сами строки отчёта. */
    public function test_shop_payroll_filters_by_worker(): void
    {
        $page = Livewire::test(Payroll::class);

        $this->assertSame(0, $page->instance()->activeFilters());

        $page->set('workerId', $this->admin->id);

        $this->assertSame(1, $page->instance()->activeFilters());
        $page->assertOk()->assertSee('Только с выработкой');

        $page->call('resetFilters');
        $this->assertSame(0, $page->instance()->activeFilters());
    }

    /**
     * Произвольный период в обзоре финансов.
     *
     * Все суммы внутри считаются «между двумя датами», поэтому открытая граница
     * подставляется сама — иначе одна пустая дата уронила бы запрос.
     */
    public function test_finance_overview_accepts_a_custom_period(): void
    {
        $page = Livewire::test(FinanceOverview::class)
            ->set('from', now()->startOfYear()->toDateString())
            ->set('to', now()->toDateString());

        $page->assertOk();
        $this->assertSame('', $page->instance()->month, 'Выбор дат отменяет выбор месяца');

        // Открытый конец: «с начала года и дальше» не должно падать.
        Livewire::test(FinanceOverview::class)
            ->set('from', now()->startOfYear()->toDateString())
            ->assertOk();

        // И открытое начало.
        Livewire::test(FinanceOverview::class)
            ->set('to', now()->toDateString())
            ->assertOk();
    }

    public function test_finance_overview_month_and_period_do_not_mix(): void
    {
        $page = Livewire::test(FinanceOverview::class)
            ->set('from', now()->subMonth()->toDateString())
            ->set('month', now()->format('Y-m'));

        $this->assertNull($page->instance()->from, 'Выбор месяца сбрасывает произвольный период');
    }

    public function test_invoices_filter_by_city(): void
    {
        $almaty = $this->deal('Счёт Алматы', ['city' => 'Алматы', 'total_price' => 100, 'prepayment' => 0]);
        $astana = $this->deal('Счёт Астана', ['city' => 'Астана', 'total_price' => 100, 'prepayment' => 0]);

        Livewire::test(Invoices::class)
            ->assertCanSeeTableRecords([$almaty, $astana])
            ->filterTable('city', 'Алматы')
            ->assertCanSeeTableRecords([$almaty])
            ->assertCanNotSeeTableRecords([$astana]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function sheet(string $month, array $attributes = [], UserRole $role = UserRole::Worker): SalarySheet
    {
        $user = User::factory()->create(['role' => $role->value]);

        return SalarySheet::create(array_merge([
            'user_id' => $user->id,
            'month' => $month,
            'salary' => 0,
            'piecework' => 0,
            'bonuses' => 0,
            'deductions' => 0,
            'advances' => 0,
            'total' => 50_000,
            'paid_amount' => 0,
            'status' => SalarySheetStatus::Draft,
        ], $attributes));
    }

    /** @param  list<Deal>  $expected */
    private function assertBoardHas(array $expected, BoardFilter $filter): void
    {
        $found = app(DoorProductionService::class)
            ->board(PipelineType::Sales, $filter)
            ->flatMap(fn (FactoryStage $stage) => $stage->deals)
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            collect($expected)->pluck('id')->sort()->values()->all(),
            $found,
            'Фильтр отобрал не те сделки'
        );
    }

    /** @param  array<string, mixed>  $attributes */
    private function deal(string $title, array $attributes = []): Deal
    {
        return Deal::create(array_merge([
            'title' => $title,
            'client_name' => 'Клиент',
            'client_phone' => '+7 700 000-00-00',
            'total_price' => 100_000,
            'due_date' => now()->addWeek(),
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => FactoryStage::firstOf(PipelineType::Sales)?->id,
        ], $attributes));
    }

    private function order(Deal $deal): Deal
    {
        return Deal::create([
            'title' => "Наряд: {$deal->title}",
            'client_name' => $deal->client_name,
            'client_phone' => $deal->client_phone,
            'total_price' => $deal->total_price,
            'due_date' => $deal->due_date,
            'status_id' => DealStatus::InProduction,
            'pipeline_type' => PipelineType::Factory,
            'parent_deal_id' => $deal->id,
            'current_stage_id' => FactoryStage::firstOf(PipelineType::Factory)?->id,
        ]);
    }
}
