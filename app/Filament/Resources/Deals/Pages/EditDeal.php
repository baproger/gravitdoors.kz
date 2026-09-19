<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Pages;

use App\Exceptions\ProductionException;
use App\Filament\Actions\DealActions;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Services\DoorProductionService;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditDeal extends EditRecord
{
    protected static string $resource = DealResource::class;

    /**
     * Шапка карточки: «Принять оплату» отдельной кнопкой, остальное — по отделам
     * (Отдел продаж · Финансы · Завод · Клиент). Сами действия общие со списком
     * и «Счетами» — `DealActions`, здесь только удаление, оно есть лишь у карточки.
     */
    protected function getHeaderActions(): array
    {
        /** @var Deal $deal */
        $deal = $this->record;

        return [
            ...DealActions::headerGroups(),

            DeleteAction::make()
                // Политику администратор проходит всегда, поэтому правило проверяется здесь.
                ->authorize(fn (): bool => auth()->user()->can('delete', $deal) && $deal->canBeDeleted())
                ->modalDescription(fn (): string => $deal->isFactoryOrder()
                    ? 'Наряд уйдёт в корзину вместе с историей этапов.'
                    : 'Сделка уйдёт в корзину вместе с позициями, платежами и историей.'),
        ];
    }

    /**
     * Перечитать карточку после действия из шапки (оплата, передача в цех,
     * блокировка): платежи, этап, статус и сводка должны показать новое состояние,
     * а не то, что было при открытии страницы.
     */
    public function refreshCard(): void
    {
        $this->record->refresh();
        $this->fillForm();
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

    /**
     * «Готово ✓» под полосой этапов наряда: у последнего этапа цеха нет соседа
     * справа, поэтому сама полоса наряд закрыть не может. Та же логика, что и
     * у планшета цеха: закрыть этап, начислить оплату, вернуть сделку продажам.
     * Кнопка с подтверждением — действие `completeStage` из шапки (DealActions).
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
