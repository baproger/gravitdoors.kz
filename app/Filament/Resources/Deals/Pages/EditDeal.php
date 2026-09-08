<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Pages;

use App\Exceptions\ProductionException;
use App\Filament\Resources\Deals\DealResource;
use App\Models\FactoryStage;
use App\Services\DoorProductionService;
use App\Services\QrCodeService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
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

    /**
     * Клик по полосе этапов. Идёт через сервис, а не через update(), поэтому
     * из карточки работают те же правила, что и при перетаскивании на канбане.
     */
    public function moveToStage(int $stageId): void
    {
        $stage = FactoryStage::query()->find($stageId);

        if (! $stage) {
            return;
        }

        if (auth()->user()->cannot('move', $this->record)) {
            Notification::make()->danger()->title('Недостаточно прав')->send();

            return;
        }

        try {
            app(DoorProductionService::class)->moveToStage($this->record, $stage, auth()->user());

            $this->refreshFormData(['current_stage_id', 'status_id']);

            $body = null;

            if ($order = $this->record->refresh()->productionOrder()->first()) {
                $body = "Наряд {$order->number} на заводе.";
            }

            Notification::make()
                ->success()
                ->title("Этап: {$stage->name}")
                ->body($body)
                ->send();
        } catch (ProductionException $e) {
            Notification::make()->danger()->title('Не получилось')->body($e->getMessage())->send();
        }
    }
}
