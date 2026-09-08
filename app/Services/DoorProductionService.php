<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\ProductionStatus;
use App\Events\DealHandedToProduction;
use App\Events\DealStageChanged;
use App\Events\ProductionCompleted;
use App\Exceptions\ProductionException;
use App\Models\Deal;
use App\Models\DoorConfiguration;
use App\Models\DoorOption;
use App\Models\FactoryStage;
use App\Models\MaterialStock;
use App\Models\ProductionLog;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Связка двух воронок «Отдел продаж ⇄ Завод».
 *
 * Два правила автоматизации из ТЗ реализованы здесь и только здесь — ни канбан,
 * ни Filament-ресурсы не двигают сделки напрямую, иначе перетаскивание карточки
 * и кнопка в карточке разошлись бы в поведении:
 *
 *  1. Сделка продаж входит на этап с флагом `triggers_production`
 *     («Передано в производство») → создаётся наряд в воронке завода,
 *     материалы списываются со склада, статус сделки → «Передано в производство».
 *
 *  2. На заводе завершается этап с флагом `completes_production`
 *     («ОТК и Упаковка») → наряд закрывается, а сделка продаж автоматически
 *     переходит в «Готово к отгрузке».
 *
 * Флаги настраиваются в админке, поэтому переименование или перестановка этапов
 * не требует правки кода.
 */
class DoorProductionService
{
    public function __construct(private readonly DoorPriceCalculator $calculator) {}

    /**
     * Перевести сделку на произвольный этап её воронки.
     * Это точка входа для drag & drop на канбане.
     */
    public function moveToStage(Deal $deal, FactoryStage $stage, ?User $actor = null): Deal
    {
        return DB::transaction(function () use ($deal, $stage, $actor): Deal {
            if ($deal->status_id->isClosed()) {
                throw ProductionException::dealClosed($deal);
            }

            if ($stage->pipeline_type !== $deal->pipeline_type) {
                throw ProductionException::wrongPipeline($deal, $stage);
            }

            $from = $deal->currentStage;

            if ($from && $from->is($stage)) {
                return $deal;
            }

            $this->guardTransition($deal, $from, $stage);

            // Движение вперёд по цеху закрывает предыдущий этап как выполненный;
            // возврат назад (брак, переделка) помечает его как отклонённый.
            if ($deal->isFactoryOrder() && $from) {
                $movingForward = $stage->order > $from->order;

                $this->closeOpenLog(
                    $deal,
                    $movingForward ? ProductionStatus::Done : ProductionStatus::Rejected,
                    $actor,
                    $movingForward ? null : "Возврат на этап «{$stage->name}»",
                );
            }

            $this->enterStage($deal, $stage, $from, $actor);

            // Автоматизация №2: этап ОТК покинут вперёд — значит, он пройден.
            if ($from?->completes_production && $stage->order > $from->order) {
                $this->finishProduction($deal, $actor);
            }

            // Автоматизация №1: вход на этап передачи в цех.
            if ($stage->triggers_production) {
                $this->handOffToProduction($deal, $actor);
            }

            return $deal->refresh();
        });
    }

    /**
     * Закрыть текущий этап цеха и переехать на следующий.
     * Кнопка «Готово ✓» в карточке наряда и на планшете цеха.
     */
    public function completeCurrentStage(Deal $order, ?User $worker = null, ?string $comment = null): Deal
    {
        if (! $order->isFactoryOrder()) {
            throw ProductionException::notAFactoryOrder($order);
        }

        return DB::transaction(function () use ($order, $worker, $comment): Deal {
            $stage = $order->currentStage;

            if (! $stage) {
                throw ProductionException::noFactoryStages();
            }

            $this->closeOpenLog($order, ProductionStatus::Done, $worker, $comment);

            $next = $stage->completes_production || $stage->is_final ? null : $stage->next();

            if ($next) {
                $this->enterStage($order, $next, $stage, $worker);

                return $order->refresh();
            }

            return $this->finishProduction($order, $worker);
        });
    }

    /**
     * Создать производственный наряд из сделки продаж.
     * Идемпотентно: повторный вызов вернёт уже существующий наряд, а не второй.
     */
    public function handOffToProduction(Deal $salesDeal, ?User $actor = null): Deal
    {
        if ($salesDeal->isFactoryOrder()) {
            return $salesDeal;
        }

        return DB::transaction(function () use ($salesDeal, $actor): Deal {
            if ($existing = $salesDeal->productionOrder()->first()) {
                return $existing;
            }

            if ($salesDeal->doorConfigurations()->doesntExist()) {
                throw ProductionException::noConfiguration($salesDeal);
            }

            $firstStage = FactoryStage::firstOf(PipelineType::Factory)
                ?? throw ProductionException::noFactoryStages();

            $order = Deal::create([
                'title' => "Наряд: {$salesDeal->title}",
                'client_name' => $salesDeal->client_name,
                'client_phone' => $salesDeal->client_phone,
                'client_address' => $salesDeal->client_address,
                'total_price' => $salesDeal->total_price,
                'cost_price' => $salesDeal->cost_price,
                'status_id' => DealStatus::InProduction,
                'pipeline_type' => PipelineType::Factory,
                'current_stage_id' => $firstStage->id,
                'parent_deal_id' => $salesDeal->id,
                'manager_id' => $salesDeal->manager_id,
                'due_date' => $salesDeal->due_date,
                'stage_entered_at' => now(),
                'production_started_at' => now(),
                'notes' => $salesDeal->notes,
            ]);

            $this->openLog($order, $firstStage, $actor);

            $salesDeal->forceFill([
                'status_id' => DealStatus::HandedToProduction,
                'production_started_at' => $salesDeal->production_started_at ?? now(),
            ])->save();

            $this->writeOffMaterials($order, $actor);

            DealHandedToProduction::dispatch($salesDeal, $order, $actor);

            return $order;
        });
    }

    /**
     * Закрыть наряд и вернуть сделку продаж в «Готово к отгрузке».
     */
    public function finishProduction(Deal $order, ?User $actor = null): Deal
    {
        if (! $order->isFactoryOrder()) {
            throw ProductionException::notAFactoryOrder($order);
        }

        return DB::transaction(function () use ($order, $actor): Deal {
            $this->closeOpenLog($order, ProductionStatus::Done, $actor);

            $order->forceFill([
                'status_id' => DealStatus::Completed,
                'production_finished_at' => now(),
            ])->save();

            $salesDeal = $order->parentDeal;

            if ($salesDeal && ! $salesDeal->status_id->isClosed()) {
                $salesDeal->forceFill([
                    'status_id' => DealStatus::ReadyToShip,
                    'production_finished_at' => now(),
                ])->save();

                $this->moveSalesDealToReadyStage($salesDeal, $actor);

                ProductionCompleted::dispatch($order, $salesDeal, $actor);
            }

            return $order->refresh();
        });
    }

    /** Взять текущий этап в работу — фиксирует исполнителя для сдельной оплаты. */
    public function startStage(Deal $order, User $worker): ProductionLog
    {
        if (! $order->isFactoryOrder()) {
            throw ProductionException::notAFactoryOrder($order);
        }

        $stage = $order->currentStage ?? throw ProductionException::noFactoryStages();

        $log = $this->openLogQuery($order)->first() ?? $this->openLog($order, $stage, $worker);

        $log->forceFill([
            'worker_id' => $worker->id,
            'status' => ProductionStatus::InProgress,
            'started_at' => $log->started_at ?? now(),
        ])->save();

        return $log;
    }

    /**
     * Пересчитать спецификацию и перенести суммы в сделку (и в её наряд).
     */
    public function syncPricing(Deal $deal): Deal
    {
        $salesDeal = $deal->salesDeal();

        if ($salesDeal->doorConfigurations()->doesntExist()) {
            return $deal;
        }

        $summary = $this->calculator->applyToDeal($salesDeal->refresh());

        // Доставка и монтаж — часть суммы для клиента, но не часть расчёта дверей:
        // они задаются в карточке и прибавляются к итогу поверх спецификации.
        $total = round($summary->total + $salesDeal->servicesCost(), 2);

        $salesDeal->forceFill([
            'total_price' => $total,
            'cost_price' => $summary->estimatedCost,
        ])->save();

        $salesDeal->productionOrder()->update([
            'total_price' => $total,
            'cost_price' => $summary->estimatedCost,
        ]);

        return $deal->refresh();
    }

    /**
     * Доска канбана: этапы воронки со сделками, готовые к отрисовке.
     *
     * Карточки в колонке ограничены: без лимита страница тянула бы из базы все
     * открытые сделки этапа, и на нескольких сотнях заказов канбан перестал бы
     * открываться. deals_count при этом считает полное количество, чтобы счётчик
     * в шапке колонки не врал.
     *
     * @param  list<int>  $expandedStageIds  этапы, где нажали «Показать ещё»
     * @return Collection<int, FactoryStage>
     */
    public function board(
        PipelineType $pipeline,
        ?string $search = null,
        ?int $managerId = null,
        int $perColumn = 20,
        array $expandedStageIds = [],
    ): Collection {
        $filter = function ($query) use ($pipeline, $search, $managerId): void {
            $query->where('pipeline_type', $pipeline->value)
                ->open()
                ->when($managerId, fn ($q) => $q->where('manager_id', $managerId))
                ->when($search, fn ($q) => $q->where(function ($inner) use ($search): void {
                    $inner->where('title', 'like', "%{$search}%")
                        ->orWhere('number', 'like', "%{$search}%")
                        ->orWhere('client_name', 'like', "%{$search}%")
                        ->orWhere('client_phone', 'like', "%{$search}%");
                }));
        };

        $stages = FactoryStage::query()
            ->ofPipeline($pipeline)
            ->active()
            ->ordered()
            ->withCount(['deals as deals_count' => $filter])
            ->get();

        // Карточки грузятся отдельным запросом на этап: лимит «по N на родителя»
        // в eager loading опирается на оконные функции, а они есть не в каждой
        // поддерживаемой СУБД — здесь важнее предсказуемость, чем один запрос.
        foreach ($stages as $stage) {
            $limit = in_array($stage->id, $expandedStageIds, true) ? $perColumn * 5 : $perColumn;

            $deals = Deal::query()
                ->where('current_stage_id', $stage->id)
                ->tap($filter)
                ->with(['manager', 'parentDeal.doorConfigurations', 'doorConfigurations'])
                ->orderByDesc('updated_at')
                ->limit($limit)
                ->get();

            $stage->setRelation('deals', $deals);
        }

        return $stages;
    }

    /**
     * Списание материалов под наряд — по всем позициям сделки сразу.
     *
     * Что списывать, известно из прайса: позиции door_options, привязанные к складу,
     * знают свой расход на м², м.п. или изделие. Потребность агрегируется по материалу,
     * чтобы на складе была одна строка расхода на позицию номенклатуры, а не десяток
     * мелких по каждой опции каждой двери.
     */
    public function writeOffMaterials(Deal $order, ?User $actor = null): void
    {
        if (! config('gravit.production.write_off_materials')) {
            return;
        }

        $required = $this->materialRequirements($order->salesDeal());

        if ($required === []) {
            return;
        }

        $allowNegative = (bool) config('gravit.production.allow_negative_stock');
        $materials = MaterialStock::query()->findMany(array_keys($required))->keyBy('id');

        foreach ($required as $materialId => $quantity) {
            $material = $materials->get($materialId);

            if (! $material || $quantity <= 0.0) {
                continue;
            }

            if (! $allowNegative && (float) $material->quantity < $quantity) {
                throw ProductionException::insufficientStock($material, $quantity);
            }

            StockMovement::create([
                'material_stock_id' => $material->id,
                'deal_id' => $order->id,
                'user_id' => $actor?->id,
                'type' => StockMovement::TYPE_OUT,
                'quantity' => $quantity,
                'price_per_unit' => $material->price_per_unit,
                'comment' => "Списание под наряд {$order->number}",
            ]);

            $material->decrement('quantity', $quantity);
        }
    }

    /**
     * Вернуть на склад то, что было списано под наряд, и не было возвращено раньше.
     *
     * Возвращается именно сальдо (списано − возвращено), поэтому повторный вызов
     * ничего не задвоит: отмена наряда, случайно нажатая дважды, не «нарисует»
     * на складе лишний металл.
     */
    public function returnMaterials(Deal $order, ?User $actor = null, string $reason = 'Возврат по отменённому наряду'): void
    {
        $balances = StockMovement::query()
            ->where('deal_id', $order->id)
            ->selectRaw('material_stock_id')
            ->selectRaw("SUM(CASE WHEN type = 'out' THEN quantity ELSE -quantity END) as net")
            ->groupBy('material_stock_id')
            ->pluck('net', 'material_stock_id');

        $materials = MaterialStock::query()->findMany($balances->keys()->all())->keyBy('id');

        foreach ($balances as $materialId => $net) {
            $net = round((float) $net, 3);
            $material = $materials->get($materialId);

            if (! $material || $net <= 0.0) {
                continue;
            }

            StockMovement::create([
                'material_stock_id' => $material->id,
                'deal_id' => $order->id,
                'user_id' => $actor?->id,
                'type' => StockMovement::TYPE_IN,
                'quantity' => $net,
                'price_per_unit' => $material->price_per_unit,
                'comment' => "{$reason} ({$order->number})",
            ]);

            $material->increment('quantity', $net);
        }
    }

    /**
     * Отменить наряд: закрыть незавершённый этап, вернуть материалы на склад
     * и снять со сделки продаж статус производства.
     */
    public function cancelProduction(Deal $order, ?User $actor = null, string $reason = 'Наряд отменён'): Deal
    {
        if (! $order->isFactoryOrder()) {
            throw ProductionException::notAFactoryOrder($order);
        }

        return DB::transaction(function () use ($order, $actor, $reason): Deal {
            $this->closeOpenLog($order, ProductionStatus::Rejected, $actor, $reason);

            $order->forceFill([
                'status_id' => DealStatus::Cancelled,
                'production_finished_at' => now(),
            ])->save();

            $this->returnMaterials($order, $actor, $reason);

            $salesDeal = $order->parentDeal;

            if ($salesDeal && ! $salesDeal->status_id->isClosed()) {
                // Сделка возвращается в работу, а не закрывается: заказ у клиента
                // остаётся, отменено только текущее производственное задание.
                $salesDeal->forceFill([
                    'status_id' => DealStatus::InWork,
                    'production_started_at' => null,
                ])->save();
            }

            return $order->refresh();
        });
    }

    /**
     * Совокупная потребность в материалах по всем позициям сделки.
     *
     * @return array<int, float> material_stock_id => количество
     */
    private function materialRequirements(Deal $salesDeal): array
    {
        $configurations = $salesDeal->doorConfigurations;

        if ($configurations->isEmpty()) {
            return [];
        }

        $selected = $configurations
            ->flatMap(fn (DoorConfiguration $c): array => array_merge(...array_map(
                fn (string $category, array $codes): array => array_map(
                    fn (string $code): string => "{$category}|{$code}",
                    $codes,
                ),
                array_keys($c->selectedCodes()),
                array_values($c->selectedCodes()),
            )))
            ->unique();

        if ($selected->isEmpty()) {
            return [];
        }

        $options = DoorOption::query()
            ->with('materialStock')
            ->active()
            ->whereNotNull('material_stock_id')
            ->where(function ($query) use ($selected): void {
                foreach ($selected as $pair) {
                    [$category, $code] = explode('|', $pair, 2);
                    $query->orWhere(fn ($q) => $q->where('category', $category)->where('code', $code));
                }
            })
            ->get()
            ->groupBy(fn (DoorOption $option): string => "{$option->category->value}|{$option->code}");

        $required = [];

        foreach ($configurations as $configuration) {
            $area = $configuration->areaSqm();
            $perimeter = $configuration->perimeterMeters();
            $quantity = max(1, $configuration->quantity);

            foreach ($configuration->selectedCodes() as $category => $codes) {
                foreach ($codes as $code) {
                    foreach ($options->get("{$category}|{$code}", collect()) as $option) {
                        $amount = round($option->consumptionFor($area, $perimeter) * $quantity, 3);

                        if ($amount <= 0.0) {
                            continue;
                        }

                        $id = (int) $option->material_stock_id;
                        $required[$id] = round(($required[$id] ?? 0.0) + $amount, 3);
                    }
                }
            }
        }

        return $required;
    }

    /**
     * Правила перехода: вперёд — только на соседний этап и только с заполненными
     * обязательными полями. Назад — свободно: возврат нужен, когда менеджер
     * ошибся или клиент передумал, и запирать его нечем.
     */
    private function guardTransition(Deal $deal, ?FactoryStage $from, FactoryStage $to): void
    {
        $movingForward = $from === null || $to->order > $from->order;

        if (! $movingForward) {
            return;
        }

        if ($from !== null) {
            $expected = $from->next();

            if ($expected !== null && ! $expected->is($to)) {
                throw ProductionException::stageSkipped($deal, $to, $expected);
            }
        }

        $missing = $to->missingFor($deal);

        if ($missing !== []) {
            throw ProductionException::requirementsNotMet($to, $missing);
        }
    }

    /** Общая часть входа на этап для канбана и для кнопки «Готово ✓». */
    private function enterStage(Deal $deal, FactoryStage $stage, ?FactoryStage $from, ?User $actor): void
    {
        $deal->forceFill([
            'current_stage_id' => $stage->id,
            'stage_entered_at' => now(),
        ])->save();

        if ($deal->isFactoryOrder()) {
            $this->openLog($deal, $stage, $actor);
        }

        $deal->setRelation('currentStage', $stage);

        DealStageChanged::dispatch($deal, $from, $stage, $actor);
    }

    private function openLog(Deal $deal, FactoryStage $stage, ?User $worker): ProductionLog
    {
        return ProductionLog::create([
            'deal_id' => $deal->id,
            'stage_id' => $stage->id,
            'worker_id' => $worker?->id,
            'started_at' => now(),
            'status' => ProductionStatus::Pending,
        ]);
    }

    private function closeOpenLog(Deal $deal, ProductionStatus $status, ?User $worker, ?string $comment = null): void
    {
        $log = $this->openLogQuery($deal)->first();

        if (! $log) {
            return;
        }

        $log->forceFill([
            'finished_at' => now(),
            'status' => $status,
            'worker_id' => $log->worker_id ?? $worker?->id,
            // Расценку фиксируем в момент закрытия: поднятие тарифа не должно
            // задним числом пересчитывать уже закрытые смены.
            'payout' => $status === ProductionStatus::Done ? $log->stage->operation_cost : 0,
            'comment' => $comment ?? $log->comment,
        ])->save();
    }

    /** @return Builder<ProductionLog> */
    private function openLogQuery(Deal $deal): Builder
    {
        return ProductionLog::query()
            ->with('stage')
            ->where('deal_id', $deal->id)
            ->whereNull('finished_at')
            ->latest('id');
    }

    /**
     * Сделка продаж после цеха встаёт на первый этап, следующий за передачей
     * в производство, — иначе она осталась бы висеть в колонке «Передано в цех»
     * со статусом «Готово к отгрузке», и канбан противоречил бы статусу.
     */
    private function moveSalesDealToReadyStage(Deal $salesDeal, ?User $actor): void
    {
        $current = $salesDeal->currentStage;

        if (! $current?->triggers_production) {
            return;
        }

        $next = $current->next();

        if ($next) {
            $this->enterStage($salesDeal, $next, $current, $actor);
        }
    }
}
