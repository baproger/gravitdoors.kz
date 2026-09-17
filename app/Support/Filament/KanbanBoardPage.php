<?php

declare(strict_types=1);

namespace App\Support\Filament;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Exceptions\ProductionException;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\User;
use App\Services\DoorProductionService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Общая механика канбана для обеих воронок.
 *
 * Живёт вне app/Filament/Pages намеренно: этот каталог сканируется
 * автодискавери панели, и абстрактная страница попала бы в меню.
 *
 * Перетаскивание карточки не двигает модель напрямую — оно вызывает
 * DoorProductionService, поэтому drag & drop запускает ту же автоматизацию,
 * что и кнопки в карточке сделки.
 */
abstract class KanbanBoardPage extends Page
{
    protected string $view = 'filament.pages.kanban-board';

    public string $search = '';

    public ?int $managerId = null;

    /** Сколько карточек показывать в колонке до нажатия «Показать ещё». */
    public int $perColumn = 20;

    /** @var list<int> */
    public array $expandedStages = [];

    abstract public static function pipeline(): PipelineType;

    /** @return Collection<int, FactoryStage> */
    public function getStages(): Collection
    {
        return app(DoorProductionService::class)->board(
            static::pipeline(),
            $this->search !== '' ? $this->search : null,
            $this->managerId,
            $this->perColumn,
            $this->expandedStages,
        );
    }

    /** Развернуть колонку целиком — карточки грузятся порциями, а не все сразу. */
    public function loadMore(int $stageId): void
    {
        if (! in_array($stageId, $this->expandedStages, true)) {
            $this->expandedStages[] = $stageId;
        }
    }

    /** Смена фильтра сбрасывает «развёрнутость»: иначе лимит колонки утекал бы между выборками. */
    public function updatedSearch(): void
    {
        $this->expandedStages = [];
    }

    public function updatedManagerId(): void
    {
        $this->expandedStages = [];
    }

    /** @return array<int, string> */
    public function getManagerOptions(): array
    {
        return User::query()
            ->whereHas('deals')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** Видит ли текущий пользователь суммы на карточках. */
    public function canSeeMoney(): bool
    {
        return auth()->user()?->role->seesMoney() ?? false;
    }

    public function moveDeal(int $dealId, int $stageId): void
    {
        $deal = Deal::query()->find($dealId);
        $stage = FactoryStage::query()->find($stageId);

        if (! $deal || ! $stage) {
            Notification::make()->danger()->title('Сделка или этап не найдены')->send();

            return;
        }

        // Права проверяем на сервере: перетащить карточку в браузере можно всегда,
        // но решает политика, а не разметка.
        if (auth()->user()?->cannot('move', $deal)) {
            Notification::make()->danger()->title('Недостаточно прав для перемещения')->send();

            return;
        }

        try {
            $before = $deal->currentStage?->name;
            app(DoorProductionService::class)->moveToStage($deal, $stage, auth()->user());

            $deal->refresh();
            $body = $before ? "«{$before}» → «{$stage->name}»" : "Этап: «{$stage->name}»";

            // Автоматизация могла сделать больше, чем просто переставить карточку —
            // говорим об этом явно, иначе списание склада выглядит как магия.
            if ($order = $deal->productionOrder()->first()) {
                if ($order->wasRecentlyCreated || $order->created_at?->diffInSeconds(now()) < 5) {
                    $body .= ". Создан наряд {$order->number}, материалы списаны.";
                }
            }

            if ($deal->isFactoryOrder() && $deal->parentDeal?->status_id->value === DealStatus::ReadyToShip->value) {
                $body .= '. Сделка продаж переведена в «Готово к отгрузке».';
            }

            Notification::make()->success()->title($deal->number)->body($body)->send();
        } catch (ProductionException $e) {
            Notification::make()->danger()->title('Перемещение отклонено')->body($e->getMessage())->send();
        }
    }

    /**
     * Подтверждение «Готово ✓» — модалка Filament, а не системное окно браузера:
     * оно не в стиле панели и не объясняет, что именно произойдёт.
     */
    public function completeStageAction(): Action
    {
        return Action::make('completeStage')
            ->requiresConfirmation()
            ->color('success')
            ->modalIcon('heroicon-o-check-badge')
            ->modalIconColor('success')
            ->modalHeading(fn (array $arguments): string => 'Этап «'.($this->orderFrom($arguments)?->currentStage->name ?? '—').'» выполнен?')
            ->modalDescription(function (array $arguments): string {
                $order = $this->orderFrom($arguments);
                $parent = $order?->parentDeal;

                return 'Наряд '.($order->number ?? '').' закроется, сдельная оплата за этап запишется на вас'
                    .($parent ? ', а сделка '.$parent->number.' перейдёт в «Готово к отгрузке».' : '.');
            })
            ->modalSubmitActionLabel('Да, закрыть этап')
            ->modalCancelActionLabel('Отмена')
            ->action(fn (array $arguments) => $this->completeStage((int) ($arguments['dealId'] ?? 0)));
    }

    /**
     * «Готово ✓» на последнем этапе цеха.
     *
     * Стрелка «→» ведёт на соседний этап, а у «ОТК и Упаковка» соседа нет —
     * без этой кнопки наряд с канбана было не закрыть, только с планшета цеха.
     * Идёт через тот же completeCurrentStage, что и планшет: закрывает этап,
     * начисляет сдельную оплату и переводит сделку продаж в «Готово к отгрузке».
     */
    public function completeStage(int $dealId): void
    {
        $order = Deal::query()->factoryOrders()->find($dealId);

        if (! $order) {
            Notification::make()->danger()->title('Наряд не найден')->send();

            return;
        }

        if (auth()->user()?->cannot('move', $order)) {
            Notification::make()->danger()->title('Недостаточно прав для закрытия этапа')->send();

            return;
        }

        try {
            $stageName = $order->currentStage->name ?? '—';
            app(DoorProductionService::class)->completeCurrentStage($order, auth()->user());

            $order->refresh();

            if ($order->status_id->isClosed()) {
                $body = "Этап «{$stageName}» закрыт, наряд завершён.";

                if ($parent = $order->parentDeal) {
                    $body .= " Сделка {$parent->number} переведена в «Готово к отгрузке».";
                }
            } else {
                $body = "«{$stageName}» → «".($order->currentStage->name ?? '—').'»';
            }

            Notification::make()->success()->title($order->number)->body($body)->send();
        } catch (ProductionException $e) {
            Notification::make()->danger()->title('Этап не закрыт')->body($e->getMessage())->send();
        }
    }

    /** @param  array<string, mixed>  $arguments */
    private function orderFrom(array $arguments): ?Deal
    {
        return Deal::query()->factoryOrders()->find((int) ($arguments['dealId'] ?? 0));
    }

    public function getCurrencySymbol(): string
    {
        return (string) config('gravit.currency.symbol');
    }
}
