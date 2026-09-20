<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealSource;
use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Filament\Pages\FactoryKanban;
use App\Filament\Pages\OverdueDeals;
use App\Filament\Pages\SalesKanban;
use App\Filament\Resources\MaterialStocks\Pages\ManageMaterialStocks;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\MaterialStock;
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
