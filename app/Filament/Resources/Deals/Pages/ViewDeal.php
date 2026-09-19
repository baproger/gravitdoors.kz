<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Pages;

use App\Filament\Actions\DealActions;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * Карточка сделки для тех, у кого доступ «только чтение».
 *
 * Отдельная страница, а не «выключенная» форма редактирования: так правка
 * недоступна не по разметке, а по маршруту — Filament сам проверит политику
 * `view`, а `update` для этой страницы не потребуется.
 */
class ViewDeal extends ViewRecord
{
    protected static string $resource = DealResource::class;

    public function getTitle(): string
    {
        /** @var Deal $deal */
        $deal = $this->record;

        return "{$deal->number} · {$deal->title}";
    }

    public function getSubheading(): ?string
    {
        return 'Просмотр: изменения недоступны на вашем уровне доступа.';
    }

    /** После действия из шапки (оплата, этап) показать новое состояние, а не снимок при открытии. */
    public function refreshCard(): void
    {
        $this->record->refresh();
        $this->fillForm();
    }

    protected function getHeaderActions(): array
    {
        /** @var Deal $deal */
        $deal = $this->record;

        return [
            Action::make('edit')
                ->label('Редактировать')
                ->icon('heroicon-o-pencil-square')
                ->url(fn (): string => DealResource::getUrl('edit', ['record' => $deal]))
                ->visible(fn (): bool => auth()->user()?->can('update', $deal) ?? false),

            // Те же группы по отделам, что в карточке: у читателя останутся только
            // разрешённые его правами (обычно «Клиент»), пустые группы Filament прячет.
            ...DealActions::headerGroups(),
        ];
    }
}
