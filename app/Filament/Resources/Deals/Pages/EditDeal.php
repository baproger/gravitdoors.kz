<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Pages;

use App\Filament\Resources\Deals\DealResource;
use App\Services\DoorProductionService;
use App\Services\QrCodeService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\HtmlString;

class EditDeal extends EditRecord
{
    protected static string $resource = DealResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('qr')
                ->label('QR для двери')
                ->icon('heroicon-o-qr-code')
                ->color('gray')
                ->modalHeading(fn (): string => "Заказ {$this->record->number}")
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Закрыть')
                // Стикер клеится на изделие: сканируя его, клиент и кладовщик
                // попадают на один и тот же публичный статус заказа.
                ->modalContent(fn (): HtmlString => new HtmlString(
                    '<div style="text-align:center">'
                    .app(QrCodeService::class)->svg($this->record->qr_code_hash
                        ? route('track.show', $this->record->qr_code_hash)
                        : url('/'), 240)
                    .'<p style="margin-top:.75rem;font-size:.75rem;word-break:break-all">'
                    .e(route('track.show', $this->record->qr_code_hash))
                    .'</p></div>',
                )),

            Action::make('track')
                ->label('Страница клиента')
                ->icon('heroicon-o-qr-code')
                ->color('gray')
                ->url(fn (): string => route('track.show', $this->record->qr_code_hash))
                ->openUrlInNewTab(),

            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        app(DoorProductionService::class)->syncPricing($this->record);
    }
}
