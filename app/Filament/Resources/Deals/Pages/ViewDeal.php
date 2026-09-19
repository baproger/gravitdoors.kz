<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Pages;

use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use App\Services\QrCodeService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\HtmlString;

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

            Action::make('qr')
                ->label('QR для двери')
                ->icon('heroicon-o-qr-code')
                ->color('gray')
                ->modalHeading(fn (): string => "Заказ {$deal->number}")
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Закрыть')
                ->modalContent(fn (): HtmlString => new HtmlString(
                    '<div style="text-align:center">'
                    .app(QrCodeService::class)->svg($deal->qr_code_hash
                        ? route('track.show', $deal->qr_code_hash)
                        : url('/'), 240)
                    .'</div>',
                )),

            Action::make('track')
                ->label('Страница клиента')
                ->icon('heroicon-o-globe-alt')
                ->color('gray')
                ->url(fn (): string => route('track.show', $deal->qr_code_hash))
                ->openUrlInNewTab(),
        ];
    }
}
