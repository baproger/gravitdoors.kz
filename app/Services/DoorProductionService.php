<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DealEventType;
use App\Enums\DealStatus;
use App\Enums\Department;
use App\Enums\PipelineType;
use App\Enums\ProductionStatus;
use App\Events\DealHandedToProduction;
use App\Events\DealStageChanged;
use App\Events\ProductionCompleted;
use App\Exceptions\ProductionException;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\DoorConfiguration;
use App\Models\DoorOption;
use App\Models\FactoryStage;
use App\Models\MaterialStock;
use App\Models\ProductionLog;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\BoardFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

            // Автоматизация №2: этап ОТК покинут вперёд — значит, он пройден.
            // Наряд не заходит на следующий этап (иначе там открылся бы и тут же
            // оплатился пустой лог), а закрывается, как по кнопке «Готово ✓».
            if ($deal->isFactoryOrder() && $from?->completes_production && $stage->order > $from->order) {
                return $this->finishProduction($deal, $actor);
            }

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

            // Завершающий этап продаж закрывает сделку: иначе она оставалась бы
            // «Готово к отгрузке» и считалась открытой — в сводке «В работе»
            // и на канбане навсегда.
            if (! $deal->isFactoryOrder() && $stage->is_final && ($from === null || $stage->order > $from->order)) {
                $deal->forceFill(['status_id' => DealStatus::Completed])->save();

                // Сделка закрыта — менеджеру начисляется процент от её суммы (см. настройки финансов).
                app(BonusAccrual::class)->forCompletedDeal($deal, $actor);
            }

            $this->syncSalesStatus($deal, $stage);

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

        if ($order->status_id->isClosed()) {
            throw ProductionException::dealClosed($order);
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
            // Только живой наряд считается «уже передано»: отменённый не в счёт,
            // иначе после отмены повторная передача молча ничего не создавала.
            if ($existing = $salesDeal->activeProductionOrder()) {
                return $existing;
            }

            // Готовый наряд — тоже «уже передано»: двери сделаны, материалы
            // списаны. Сделку, возвращённую назад и снова доведённую до передачи,
            // второй раз на завод не отправляем — иначе склад списывался бы дважды.
            if ($produced = $salesDeal->completedProductionOrder()) {
                return $produced;
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

            // Без исполнителя: наряд открыл менеджер, а работать будет цех.
            // Иначе рабочий, нажавший «Готово» без «Взять в работу», отдавал
            // бы сдельную оплату за раскрой менеджеру.
            $this->openLog($order, $firstStage, null);

            $salesDeal->forceFill([
                'status_id' => DealStatus::HandedToProduction,
                'production_started_at' => $salesDeal->production_started_at ?? now(),
            ])->save();

            $this->writeOffMaterials($order, $actor);

            DealEvent::record(
                $salesDeal,
                DealEventType::HandedToProduction,
                "Открыт наряд {$order->number}, материалы списаны со склада",
                $actor,
            );

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

        if ($order->status_id->isClosed()) {
            throw ProductionException::dealClosed($order);
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

                DealEvent::record(
                    $salesDeal,
                    DealEventType::ProductionCompleted,
                    "Наряд {$order->number} закрыт, заказ готов к отгрузке",
                    $actor,
                    department: Department::Factory,
                );

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

        if ($order->status_id->isClosed()) {
            throw ProductionException::dealClosed($order);
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
            'cost_price' => $summary->estimatedCost(),
        ])->save();

        $salesDeal->productionOrder()->update([
            'total_price' => $total,
            'cost_price' => $summary->estimatedCost(),
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
        ?BoardFilter $criteria = null,
        int $perColumn = 20,
        array $expandedStageIds = [],
    ): Collection {
        $criteria ??= new BoardFilter;

        // Одни и те же условия и для счётчика в шапке колонки, и для карточек:
        // разойдись они — колонка показывала бы «12», а карточек было бы семь.
        $filter = function ($query) use ($pipeline, $criteria): void {
            $query->where('pipeline_type', $pipeline->value)->open();
            $criteria->apply($query);
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
                ->with(['manager', 'parentDeal.doorConfigurations', 'doorConfigurations', 'productionOrder.currentStage', 'stageVisits'])
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

        DealEvent::record(
            $order->salesDeal(),
            DealEventType::Materials,
            'Списано со склада: '.count($required).' позиций номенклатуры',
            $actor,
            department: Department::Warehouse,
        );
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
        $returned = 0;

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
            $returned++;
        }

        if ($returned > 0) {
            DealEvent::record(
                $order->salesDeal(),
                DealEventType::Materials,
                "Возвращено на склад: {$returned} позиций номенклатуры",
                $actor,
                department: Department::Warehouse,
            );
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

        if ($order->status_id->isClosed()) {
            throw ProductionException::dealClosed($order);
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

                // Сделка уходит с «Передано в производство» на шаг назад: наряда
                // больше нет, а повторная передача сработает только при новом
                // входе на этот этап.
                $current = $salesDeal->currentStage;

                if ($current?->triggers_production && ($previous = $current->previous())) {
                    $this->enterStage($salesDeal, $previous, $current, $actor);
                }

                DealEvent::record(
                    $salesDeal,
                    DealEventType::ProductionCancelled,
                    "Наряд {$order->number} отменён: {$reason}. Материалы возвращены на склад",
                    $actor,
                );
            }

            return $order->refresh();
        });
    }

    /**
     * Факт расхода материалов по наряду: списать сверх плана или вернуть излишек.
     * Плановый расход уже списан при передаче в цех — здесь только разница.
     */
    public function adjustMaterials(Deal $order, int $materialId, float $quantity, string $direction, ?string $comment, ?User $actor = null): StockMovement
    {
        if (! $order->isFactoryOrder()) {
            throw ProductionException::notAFactoryOrder($order);
        }

        if ($order->status_id->isClosed()) {
            throw ProductionException::dealClosed($order);
        }

        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Количество должно быть больше нуля.']);
        }

        if (! in_array($direction, [StockMovement::TYPE_IN, StockMovement::TYPE_OUT], true)) {
            throw ValidationException::withMessages(['direction' => 'Неизвестное движение склада.']);
        }

        $material = MaterialStock::query()->findOrFail($materialId);

        return DB::transaction(function () use ($order, $material, $quantity, $direction, $comment, $actor): StockMovement {
            $movement = StockMovement::create([
                'material_stock_id' => $material->id,
                'deal_id' => $order->id,
                'user_id' => $actor?->id,
                'type' => $direction,
                'quantity' => $quantity,
                'price_per_unit' => $material->price_per_unit,
                'comment' => trim(($direction === StockMovement::TYPE_OUT ? 'Факт сверх плана' : 'Возврат излишка')
                    ." по наряду {$order->number}".($comment ? ": {$comment}" : '')),
            ]);

            $direction === StockMovement::TYPE_OUT
                ? $material->decrement('quantity', $quantity)
                : $material->increment('quantity', $quantity);

            DealEvent::record(
                $order->salesDeal(),
                DealEventType::Materials,
                ($direction === StockMovement::TYPE_OUT ? 'Списано сверх плана: ' : 'Возвращён излишек: ')
                    .rtrim(rtrim((string) $quantity, '0'), '.')." {$material->unit->getLabel()} · {$material->name}",
                $actor,
                department: Department::Factory,
            );

            return $movement;
        });
    }

    /**
     * Придержать или отпустить отгрузку. Ставят финансы, видят все.
     */
    public function setShipmentBlock(Deal $deal, bool $blocked, ?string $reason, ?User $actor = null): Deal
    {
        if ($deal->isFactoryOrder()) {
            throw ProductionException::notASalesDeal($deal);
        }

        if ($blocked && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Укажите причину блокировки: её увидят продажи и цех.']);
        }

        $deal->forceFill($blocked
            ? ['shipment_blocked_at' => now(), 'shipment_block_reason' => trim((string) $reason), 'shipment_blocked_by' => $actor?->id]
            : ['shipment_blocked_at' => null, 'shipment_block_reason' => null, 'shipment_blocked_by' => null],
        )->save();

        DealEvent::record(
            $deal,
            DealEventType::Updated,
            $blocked ? "Отгрузка заблокирована: {$reason}" : 'Блокировка отгрузки снята',
            $actor,
            department: Department::Finance,
        );

        return $deal->refresh();
    }

    /**
     * Отменить сделку продаж: клиент отказался. Живой наряд отменяется вместе
     * с ней (материалы возвращаются), сделка закрывается статусом «Отменена».
     * Единственный путь к этому статусу — поле «Статус» в карточке только для чтения.
     */
    public function cancelDeal(Deal $deal, ?User $actor = null, string $reason = 'Сделка отменена'): Deal
    {
        if ($deal->isFactoryOrder()) {
            throw ProductionException::notASalesDeal($deal);
        }

        if ($deal->status_id->isClosed()) {
            throw ProductionException::dealClosed($deal);
        }

        return DB::transaction(function () use ($deal, $actor, $reason): Deal {
            if ($order = $deal->activeProductionOrder()) {
                $this->cancelProduction($order, $actor, $reason);
                $deal->refresh();
            }

            $deal->forceFill(['status_id' => DealStatus::Cancelled])->save();

            DealEvent::record($deal, DealEventType::Cancelled, "Сделка отменена: {$reason}", $actor);

            return $deal->refresh();
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
            // Без фильтра по активности: дверь делается из того, что в ней
            // выбрано, даже если позицию уже сняли с продажи.
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
     * Статус сделки продаж следует за этапом, а не живёт своей жизнью: иначе
     * сделка, возвращённая с «Готово к отгрузке» на «Замер», так и значилась бы
     * готовой к отгрузке. Завершение и отмена ставятся отдельно, здесь — только
     * рабочие статусы.
     */
    private function syncSalesStatus(Deal $deal, FactoryStage $stage): void
    {
        if ($deal->isFactoryOrder() || $deal->status_id->isClosed() || $stage->is_final) {
            return;
        }

        $trigger = FactoryStage::query()
            ->ofPipeline(PipelineType::Sales)
            ->where('triggers_production', true)
            ->first();

        $produced = $deal->completedProductionOrder() !== null;

        $status = match (true) {
            $trigger !== null && $stage->order > $trigger->order => DealStatus::ReadyToShip,
            // На этапе передачи статус ставит сама передача; готовому заказу — «Готово к отгрузке».
            $stage->triggers_production => $produced ? DealStatus::ReadyToShip : $deal->status_id,
            $stage->is_initial && $deal->status_id === DealStatus::New => DealStatus::New,
            default => DealStatus::InWork,
        };

        if ($deal->status_id !== $status) {
            $deal->forceFill(['status_id' => $status])->save();
        }
    }

    /**
     * Правила перехода: вперёд — только на соседний этап и только с заполненными
     * обязательными полями. Назад — свободно: возврат нужен, когда менеджер
     * ошибся или клиент передумал, и запирать его нечем.
     */
    private function guardTransition(Deal $deal, ?FactoryStage $from, FactoryStage $to): void
    {
        // Пока завод работает, сделку продаж ведёт он. Ручной переход вперёд
        // обгонял бы производство (сделка «Готово к отгрузке», а дверь ещё на
        // покраске), назад — отрывал бы сделку от живого наряда. Дальше её
        // переведёт finishProduction(), который идёт мимо этой проверки.
        if ($order = $deal->activeProductionOrder()) {
            throw ProductionException::waitingForFactory($deal, $order);
        }

        $movingForward = $from === null || $to->order > $from->order;

        if (! $movingForward) {
            return;
        }

        // Блокировка финансов держит сделку до отгрузки: цех работает, а вперёд
        // за «Передано в производство» заказ не уходит, пока клиент должен.
        if (! $deal->isFactoryOrder() && $deal->isShipmentBlocked()) {
            $trigger = FactoryStage::query()
                ->ofPipeline(PipelineType::Sales)
                ->where('triggers_production', true)
                ->first();

            if ($trigger && $to->order > $trigger->order) {
                throw ProductionException::shipmentBlocked($deal);
            }
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
            // Исполнитель следующего этапа неизвестен: тот, кто закрыл сварку,
            // не обязательно красит. Его назначит «Взять в работу» или «Готово».
            $this->openLog($deal, $stage, null);
        }

        $deal->setRelation('currentStage', $stage);

        DealEvent::record(
            $deal,
            DealEventType::StageChanged,
            $from ? "Этап: «{$from->name}» → «{$stage->name}»" : "Этап: «{$stage->name}»",
            $actor,
        );

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

        // Оплата — тому, кто взял этап в работу, а без «Взять в работу» — тому,
        // кто закрыл, но только если это цех. Менеджер или администратор,
        // двигающий наряд с канбана, дверь не варил: этап остаётся без
        // исполнителя и без начисления, а не уходит в зарплату руководству.
        $closer = $worker?->isFactoryStaff() ? $worker : null;
        $workerId = $log->worker_id ?? $closer?->id;

        $log->forceFill([
            'finished_at' => now(),
            'status' => $status,
            'worker_id' => $workerId,
            // Расценку фиксируем в момент закрытия: поднятие тарифа не должно
            // задним числом пересчитывать уже закрытые смены.
            'payout' => $status === ProductionStatus::Done && $workerId !== null ? $log->stage->operation_cost : 0,
            'comment' => $comment ?? $log->comment,
        ])->save();

        if ($status === ProductionStatus::Done) {
            DealEvent::record(
                $deal->salesDeal(),
                DealEventType::ProductionStage,
                "Цех закрыл этап «{$log->stage->name}»"
                    .($log->worker ? ", исполнитель {$log->worker->name}" : ', без исполнителя — оплата не начислена'),
                $log->worker ?? $worker,
                department: Department::Factory,
            );
        }
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
