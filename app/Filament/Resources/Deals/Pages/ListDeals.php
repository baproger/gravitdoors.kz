<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Pages;

use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListDeals extends ListRecords
{
    protected static string $resource = DealResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Новая сделка'),
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
            'sales' => Tab::make('Отдел продаж')
                ->icon('heroicon-o-briefcase')
                ->badge(Deal::query()->sales()->open()->count())
                ->modifyQueryUsing(fn ($query) => $query->sales()),

            'factory' => Tab::make('Завод')
                ->icon('heroicon-o-cog-6-tooth')
                ->badge(Deal::query()->factory()->open()->count())
                ->modifyQueryUsing(fn ($query) => $query->factory()),

            'all' => Tab::make('Все')
                ->badge(Deal::query()->count()),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return $this->seesSalesPipeline() ? 'sales' : null;
    }

    private function seesSalesPipeline(): bool
    {
        return auth()->user()?->role->seesMoney() ?? false;
    }
}
