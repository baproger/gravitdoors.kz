<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Pages;

use App\Enums\DealStatus;
use App\Enums\Permission;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use App\Services\AccessControl;
use App\Support\Money;
use App\Support\Plural;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;

class ListDeals extends ListRecords
{
    protected static string $resource = DealResource::class;

    public function getTitle(): string
    {
        return 'Сделки';
    }

    /** «Сделки › Список» только дублировали заголовок. */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Новая сделка')->icon('heroicon-m-plus'),
        ];
    }

    /** Полоса показателей над вкладками и таблицей. */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.resources.deals.summary')
                ->visible(fn (): bool => $this->seesSalesPipeline()),
            $this->getTabsContentComponent(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
            EmbeddedTable::make(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
        ]);
    }

    /**
     * Четыре числа, ради которых менеджер открывает список: сколько в работе,
     * что стоит на заводе, что просрочено и сколько денег ещё должны клиенты.
     *
     * @return list<array{label: string, value: string, hint: string, span: int, hero: bool, alert: bool}>
     */
    public function summary(): array
    {
        $open = Deal::query()->visibleTo(auth()->user())->sales()->open();

        $inWork = (clone $open)->count();
        $inWorkSum = (float) (clone $open)->sum('total_price');

        $atFactory = (clone $open)
            ->whereHas('productionOrders', fn ($query) => $query->whereNotIn('status_id', [
                DealStatus::Completed->value,
                DealStatus::Cancelled->value,
            ]))
            ->count();

        $overdue = (clone $open)->whereDate('due_date', '<', today())->count();

        $due = (float) (clone $open)
            ->selectRaw('SUM(CASE WHEN total_price > prepayment THEN total_price - prepayment ELSE 0 END) as due')
            ->value('due');

        return [
            [
                'label' => 'В работе',
                'value' => (string) $inWork,
                'hint' => 'на '.Money::format($inWorkSum),
                'span' => 3,
                'hero' => true,
                'alert' => false,
            ],
            [
                'label' => 'Ждут завод',
                'value' => (string) $atFactory,
                'hint' => $atFactory > 0 ? Plural::choose($atFactory, 'наряд', 'наряда', 'нарядов').' в цеху' : 'цех свободен',
                'span' => 3,
                'hero' => false,
                'alert' => false,
            ],
            [
                'label' => 'Просрочено',
                'value' => (string) $overdue,
                'hint' => $overdue > 0 ? 'срок сдачи прошёл' : 'всё в срок',
                'span' => 3,
                'hero' => false,
                'alert' => $overdue > 0,
            ],
            [
                'label' => 'Ожидаем оплату',
                'value' => Money::format($due),
                'hint' => 'остаток по открытым сделкам',
                'span' => 3,
                'hero' => false,
                'alert' => false,
            ],
        ];
    }

    /**
     * Вкладки воронок. Цеху вкладка продаж не показывается: его запрос всё равно
     * ограничен нарядами, и на «Отделе продаж» он видел бы пустой список.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        if (! $this->seesSalesPipeline()) {
            return [];
        }

        return [
            // Вкладка и её счётчик должны показывать одно и то же: в рабочих
            // вкладках только открытые записи, закрытые — в «Все».
            'sales' => Tab::make('Отдел продаж')
                ->icon('heroicon-o-briefcase')
                ->badge(Deal::query()->visibleTo(auth()->user())->sales()->open()->count())
                ->modifyQueryUsing(fn ($query) => $query->sales()->open()),

            'factory' => Tab::make('Завод')
                ->icon('heroicon-o-cog-6-tooth')
                ->badge(Deal::query()->visibleTo(auth()->user())->factoryOrders()->open()->count())
                ->modifyQueryUsing(fn ($query) => $query->factoryOrders()->open()),

            // Закрытые сделки никуда не пропадают: завершённые и отменённые — здесь,
            // это и есть архив клиентов. Сводка по клиентам — страница «Клиенты».
            'closed' => Tab::make('Закрытые')
                ->icon('heroicon-o-archive-box')
                ->badge(Deal::query()->visibleTo(auth()->user())->sales()->closed()->count())
                ->modifyQueryUsing(fn ($query) => $query->sales()->closed()->orderByDesc('updated_at')),

            // Счётчик считается своим запросом, поэтому правило про завершённые
            // наряды повторяется и здесь: иначе во вкладке стояло бы «2», а в
            // таблице лежала бы одна строка — ровно то, на что и пожаловались.
            'all' => Tab::make('Все, включая закрытые')
                ->badge(Deal::query()->visibleTo(auth()->user())->withoutFinishedOrders(auth()->user())->count()),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return $this->seesSalesPipeline() ? 'sales' : null;
    }

    private function seesSalesPipeline(): bool
    {
        return AccessControl::can(Permission::WorkSalesKanban);
    }
}
