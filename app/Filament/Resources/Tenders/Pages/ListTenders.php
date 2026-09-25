<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tenders\Pages;

use App\Enums\TenderStatus;
use App\Filament\Resources\Tenders\TenderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListTenders extends ListRecords
{
    protected static string $resource = TenderResource::class;

    public function getTitle(): string
    {
        return 'Тендеры';
    }

    public function getSubheading(): ?string
    {
        return 'Закупки, на которые подаём заявку. Выигранный лот становится сделкой в воронке продаж.';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Новый тендер')->icon('heroicon-m-plus')];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $base = fn (): Builder => TenderResource::getEloquentQuery();

        return [
            'active' => Tab::make('В работе')
                ->badge($base()->active()->count())
                ->modifyQueryUsing(fn ($query) => $query->active()),
            'soon' => Tab::make('Срок подачи близко')
                ->badge(($soon = $base()->deadlineSoon()->count()) > 0 ? $soon : null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn ($query) => $query->deadlineSoon()),
            'won' => Tab::make('Выиграли')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TenderStatus::Won->value)),
            'lost' => Tab::make('Проиграли и отказались')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [TenderStatus::Lost->value, TenderStatus::Declined->value])),
            'all' => Tab::make('Все'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'active';
    }
}
