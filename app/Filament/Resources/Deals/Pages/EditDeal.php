<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Pages;

use App\Exceptions\ProductionException;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Services\DoorProductionService;
use App\Services\QrCodeService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\HtmlString;

class EditDeal extends EditRecord
{
    protected static string $resource = DealResource::class;

    protected function getHeaderActions(): array
    {
        /** @var Deal $deal */
        $deal = $this->record;

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

            Action::make('cancelDeal')
                ->label('Отменить сделку')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Отменить сделку?')
                ->modalDescription(fn (): string => $deal->activeProductionOrder()
                    ? 'Наряд в цеху будет отменён, списанные материалы вернутся на склад. Сделка получит статус «Отменена» и закроется.'
                    : 'Сделка получит статус «Отменена» и закроется. Обратно открыть её из интерфейса нельзя.')
                ->schema([
                    Textarea::make('reason')
                        ->label('Причина')
                        ->required()
                        ->rows(2),
                ])
                ->modalSubmitActionLabel('Да, отменить')
                ->authorize(fn (): bool => auth()->user()->can('update', $deal))
                ->visible(fn (): bool => ! $deal->isFactoryOrder() && ! $deal->status_id->isClosed())
                ->action(function (array $data) use ($deal): void {
                    try {
                        app(DoorProductionService::class)->cancelDeal($deal, auth()->user(), $data['reason']);
                    } catch (ProductionException $e) {
                        Notification::make()->danger()->title('Сделка не отменена')->body($e->getMessage())->send();

                        return;
                    }

                    $this->refreshFormData(['status_id', 'current_stage_id']);

                    Notification::make()->success()->title("Сделка {$deal->number} отменена")->send();
                }),

            DeleteAction::make()
                // Политику администратор проходит всегда, поэтому правило проверяется здесь.
                ->authorize(fn (): bool => auth()->user()->can('delete', $deal) && $deal->canBeDeleted())
                ->modalDescription(fn (): string => $deal->isFactoryOrder()
                    ? 'Наряд уйдёт в корзину вместе с историей этапов.'
                    : 'Сделка уйдёт в корзину вместе с позициями, платежами и историей.'),
        ];
    }

    protected function afterSave(): void
    {
        /** @var Deal $record */
        $record = $this->record;
        $deal = app(DoorProductionService::class)->syncPricing($record);

        // Спецификацию урезали, а платежи остались: правило «платежи ≤ сумма»
        // проверяется на каждом платеже, но пересчёт суммы идёт после них.
        if ((float) $deal->prepayment > (float) $deal->total_price && (float) $deal->total_price > 0) {
            Notification::make()
                ->warning()
                ->title('Платежи больше суммы сделки')
                ->body('После пересчёта спецификации сумма сделки стала меньше внесённых платежей. Проверьте позиции или оформите возврат.')
                ->persistent()
                ->send();
        }
    }

    /** Подтверждение закрытия наряда — модалка Filament вместо системного confirm(). */
    public function completeStageAction(): Action
    {
        /** @var Deal $order */
        $order = $this->record;

        return Action::make('completeStage')
            ->requiresConfirmation()
            ->color('success')
            ->modalIcon('heroicon-o-check-badge')
            ->modalIconColor('success')
            ->modalHeading(fn (): string => 'Этап «'.($order->currentStage->name ?? '—').'» выполнен?')
            ->modalDescription(fn (): string => 'Наряд '.$order->number.' закроется, сдельная оплата за этап запишется на вас'
                .($order->parentDeal ? ', а сделка '.$order->parentDeal->number.' перейдёт в «Готово к отгрузке».' : '.'))
            ->modalSubmitActionLabel('Да, закрыть наряд')
            ->modalCancelActionLabel('Отмена')
            ->action(fn () => $this->completeStage());
    }

    /**
     * «Готово ✓» под полосой этапов наряда: у последнего этапа цеха нет соседа
     * справа, поэтому сама полоса наряд закрыть не может. Та же логика, что и
     * у планшета цеха: закрыть этап, начислить оплату, вернуть сделку продажам.
     */
    public function completeStage(): void
    {
        /** @var Deal $order */
        $order = $this->record;

        if (! $order->isFactoryOrder()) {
            return;
        }

        if (auth()->user()->cannot('move', $order)) {
            Notification::make()->danger()->title('Недостаточно прав')->send();

            return;
        }

        try {
            $stageName = $order->currentStage->name ?? '—';
            app(DoorProductionService::class)->completeCurrentStage($order, auth()->user());

            $order->refresh();
            $this->refreshFormData(['current_stage_id', 'status_id']);

            if ($order->status_id->isClosed()) {
                $body = $order->parentDeal
                    ? "Сделка {$order->parentDeal->number} переведена в «Готово к отгрузке»."
                    : null;

                Notification::make()->success()->title("Наряд завершён: «{$stageName}» закрыт")->body($body)->send();

                return;
            }

            Notification::make()->success()->title('Этап: '.($order->currentStage->name ?? '—'))->send();
        } catch (ProductionException $e) {
            Notification::make()->danger()->title('Этап не закрыт')->body($e->getMessage())->send();
        }
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

            if ($order = $this->record->refresh()->activeProductionOrder()) {
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
