<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DealEventType;
use App\Enums\PipelineType;
use App\Enums\StageRequirement;
use App\Exceptions\PipelineException;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\FactoryStage;
use App\Models\ProductionLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Настройка воронок: добавление, порядок, цвет, скрытие и удаление этапов.
 *
 * Все правки идут через этот сервис, а не через update() модели: у этапа
 * есть связи, которые молча ломаются. Сделки при удалении этапа теряли бы
 * его (current_stage_id обнуляется), а записи цеха удалялись бы вместе с
 * этапом — вместе с основой для зарплаты.
 *
 * Порядок хранится в `order`, но руками его никто не задаёт: после каждой
 * правки сервис расставляет 10, 20, 30… — рабочие этапы сначала, завершающие
 * в конце, — и отмечает первый видимый рабочий этап как начальный.
 */
class PipelineStageService
{
    /** Палитра совпадает с цветами колонок канбана и полосы этапов. */
    public const COLORS = [
        'gray' => 'Серый',
        'info' => 'Голубой',
        'primary' => 'Синий',
        'success' => 'Зелёный',
        'warning' => 'Оранжевый',
        'danger' => 'Красный',
    ];

    public const NAME_MAX = 60;

    /** Временный порядок для новой строки: normalize() поставит её в конец своей группы. */
    private const AT_THE_END = 1_000_000;

    /** @return Collection<int, FactoryStage> */
    public function stagesOf(PipelineType $pipeline): Collection
    {
        return FactoryStage::query()
            ->ofPipeline($pipeline)
            ->withCount('deals')
            ->ordered()
            ->get();
    }

    public function add(PipelineType $pipeline, string $name, bool $final = false): FactoryStage
    {
        $name = $this->validName($pipeline, $name);

        return DB::transaction(function () use ($pipeline, $name, $final): FactoryStage {
            $colors = array_keys(self::COLORS);
            $count = FactoryStage::query()->ofPipeline($pipeline)->count();

            $stage = FactoryStage::create([
                'pipeline_type' => $pipeline->value,
                'code' => $this->uniqueCode($pipeline, $name),
                'name' => $name,
                'order' => self::AT_THE_END,
                'color' => $colors[$count % count($colors)],
                'is_final' => $final,
                'is_active' => true,
            ]);

            $this->normalize($pipeline);

            return $stage->refresh();
        });
    }

    /** Код этапа при переименовании не меняется: на него опираются сидеры и отчёты. */
    public function rename(FactoryStage $stage, string $name): void
    {
        $stage->update(['name' => $this->validName($stage->pipeline_type, $name, $stage)]);
    }

    public function recolor(FactoryStage $stage, string $color): void
    {
        if (! array_key_exists($color, self::COLORS)) {
            throw PipelineException::invalidColor();
        }

        $stage->update(['color' => $color]);
    }

    /** Сдвиг на одну позицию внутри своей группы; у края группы ничего не происходит. */
    public function move(FactoryStage $stage, int $direction): void
    {
        DB::transaction(function () use ($stage, $direction): void {
            $ids = $this->group($stage)->pluck('id')->values()->all();
            $index = array_search($stage->id, $ids, true);
            $target = $index === false ? -1 : $index + ($direction < 0 ? -1 : 1);

            if ($index === false || $target < 0 || $target >= count($ids)) {
                return;
            }

            [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

            $this->applyGroupOrder($stage->pipeline_type, $stage->is_final, $ids);
        });
    }

    /**
     * Перетаскивание: поставить этап перед другим.
     *
     * Без цели — в конец своей группы. Если бросили на этап чужой группы,
     * рабочий встаёт последним среди рабочих, завершающий — первым среди
     * завершающих: то есть к границе групп, а не в чужую группу. Сделать этап
     * завершающим — отдельное осознанное действие, а не случайный промах мышью.
     */
    public function placeBefore(FactoryStage $stage, ?FactoryStage $target): void
    {
        if ($target && $target->pipeline_type !== $stage->pipeline_type) {
            throw PipelineException::otherPipeline();
        }

        DB::transaction(function () use ($stage, $target): void {
            $ids = $this->group($stage)
                ->pluck('id')
                ->reject(fn (int $id): bool => $id === $stage->id)
                ->values()
                ->all();

            if ($target === null || $target->is($stage)) {
                $ids[] = $stage->id;
            } elseif ($target->is_final !== $stage->is_final) {
                if ($stage->is_final) {
                    array_unshift($ids, $stage->id);
                } else {
                    $ids[] = $stage->id;
                }
            } else {
                $position = array_search($target->id, $ids, true);
                array_splice($ids, $position === false ? count($ids) : $position, 0, [$stage->id]);
            }

            $this->applyGroupOrder($stage->pipeline_type, $stage->is_final, $ids);
        });
    }

    public function setFinal(FactoryStage $stage, bool $final): void
    {
        if ($stage->is_final === $final) {
            return;
        }

        if ($final && $stage->triggers_production) {
            throw PipelineException::triggerCannotBeFinal($stage);
        }

        if ($final && $stage->is_active && $this->activeWorkingCount($stage->pipeline_type, $stage) === 0) {
            throw PipelineException::needWorkingStage();
        }

        DB::transaction(function () use ($stage, $final): void {
            $stage->update(['is_final' => $final, 'order' => self::AT_THE_END]);
            $this->normalize($stage->pipeline_type);
        });
    }

    /**
     * Автоматика воронки: в продажах — «передаёт сделку в производство»,
     * на заводе — «завершает производство». Такой этап в воронке один:
     * включение на одном снимает флаг со всех остальных.
     */
    public function setAutomation(FactoryStage $stage, bool $enabled): void
    {
        $column = self::automationColumn($stage->pipeline_type);

        // Автоматика в воронке ровно одна: выключить её нельзя, только передать
        // другому этапу. Без неё сделки не доходили бы до завода, а наряды — до продаж.
        if (! $enabled) {
            throw PipelineException::automationRequired($stage);
        }

        if ($column === 'triggers_production' && $stage->is_final) {
            throw PipelineException::triggerCannotBeFinal($stage);
        }

        if (! $stage->is_active) {
            throw PipelineException::hiddenAutomation($stage);
        }

        DB::transaction(function () use ($stage, $column): void {
            FactoryStage::query()
                ->ofPipeline($stage->pipeline_type)
                ->whereKeyNot($stage->id)
                ->update([$column => false]);

            $stage->update([$column => true]);
        });
    }

    public function automationStage(PipelineType $pipeline): ?FactoryStage
    {
        return FactoryStage::query()
            ->ofPipeline($pipeline)
            ->where(self::automationColumn($pipeline), true)
            ->first();
    }

    public static function automationColumn(PipelineType $pipeline): string
    {
        return $pipeline === PipelineType::Sales ? 'triggers_production' : 'completes_production';
    }

    /**
     * Норматив, сдельная оплата, описание и обязательные поля.
     *
     * Обязательные поля — только у продаж: это данные клиента и договора,
     * заполняет их менеджер. Сдельная оплата — только у цеха.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateSettings(FactoryStage $stage, array $data): void
    {
        $hours = round((float) ($data['estimated_hours'] ?? $stage->estimated_hours), 2);
        $cost = round((float) ($data['operation_cost'] ?? $stage->operation_cost), 2);

        if ($hours < 0 || $hours > 999) {
            throw PipelineException::invalidHours();
        }

        if ($cost < 0) {
            throw PipelineException::invalidCost();
        }

        $isSales = $stage->pipeline_type === PipelineType::Sales;

        $required = $isSales
            ? collect($data['required_fields'] ?? [])
                ->map(fn (mixed $value): string => $value instanceof StageRequirement ? $value->value : (string) $value)
                ->filter(fn (string $value): bool => StageRequirement::tryFrom($value) !== null)
                ->unique()
                ->values()
                ->all()
            : [];

        $description = trim((string) ($data['description'] ?? ''));

        $stage->update([
            'description' => $description === '' ? null : Str::limit($description, 500, ''),
            'estimated_hours' => $hours,
            'operation_cost' => $isSales ? 0 : $cost,
            'required_fields' => $required,
        ]);
    }

    /** Скрытый этап пропадает из канбана и полосы этапов, но история по нему остаётся. */
    public function setActive(FactoryStage $stage, bool $active): void
    {
        if ($stage->is_active === $active) {
            return;
        }

        if (! $active) {
            $deals = $this->dealsOn($stage);

            if ($deals > 0) {
                throw PipelineException::hideWithDeals($stage, $deals);
            }

            if ($stage->triggers_production || $stage->completes_production) {
                throw PipelineException::automationStage($stage);
            }

            if (! $stage->is_final && $this->activeWorkingCount($stage->pipeline_type, $stage) === 0) {
                throw PipelineException::needWorkingStage();
            }
        }

        DB::transaction(function () use ($stage, $active): void {
            $stage->update(['is_active' => $active]);
            $this->normalize($stage->pipeline_type);
        });
    }

    /**
     * Удаление этапа. Сделки с него переносятся на выбранный этап той же
     * воронки, и в истории каждой остаётся запись, куда и почему она ушла.
     */
    public function delete(FactoryStage $stage, ?FactoryStage $moveTo = null, ?User $actor = null): void
    {
        if ($stage->triggers_production || $stage->completes_production) {
            throw PipelineException::automationStage($stage);
        }

        if (ProductionLog::query()->where('stage_id', $stage->id)->exists()) {
            throw PipelineException::hasWorkshopHistory($stage);
        }

        if (! $stage->is_final && $stage->is_active && $this->activeWorkingCount($stage->pipeline_type, $stage) === 0) {
            throw PipelineException::needWorkingStage();
        }

        // С удалёнными в корзину тоже: иначе при восстановлении сделка вернулась бы без этапа.
        $deals = Deal::withTrashed()->where('current_stage_id', $stage->id)->get();

        if ($deals->isNotEmpty()) {
            if (! $moveTo || $moveTo->is($stage) || $moveTo->pipeline_type !== $stage->pipeline_type) {
                throw PipelineException::chooseMoveTarget($stage, $deals->count());
            }

            if (! $moveTo->is_active) {
                throw PipelineException::moveToHidden($moveTo);
            }

            // На завершающий этап сделки не переносятся: они оказались бы «закрытыми»
            // по этапу, но открытыми по статусу.
            if ($moveTo->is_final) {
                throw PipelineException::moveToFinal($moveTo);
            }
        }

        DB::transaction(function () use ($stage, $moveTo, $deals, $actor): void {
            foreach ($deals as $deal) {
                // Обычный save(): этап и время входа в ленту не идут (DealFieldLabels),
                // а наблюдатель должен закрыть старый заход и открыть новый.
                $deal->forceFill([
                    'current_stage_id' => $moveTo->id,
                    'stage_entered_at' => now(),
                ])->save();

                DealEvent::record(
                    $deal,
                    DealEventType::StageChanged,
                    "Этап «{$stage->name}» удалён из воронки — сделка перенесена на «{$moveTo->name}»",
                    $actor,
                );
            }

            $pipeline = $stage->pipeline_type;
            $stage->delete();

            $this->normalize($pipeline);
        });
    }

    public function dealsOn(FactoryStage $stage): int
    {
        return Deal::withTrashed()->where('current_stage_id', $stage->id)->count();
    }

    public function normalize(PipelineType $pipeline): void
    {
        $stages = FactoryStage::query()->ofPipeline($pipeline)->ordered()->get()->keyBy('id');

        $ids = [
            ...$stages->filter(fn (FactoryStage $s): bool => ! $s->is_final)->keys()->all(),
            ...$stages->filter(fn (FactoryStage $s): bool => $s->is_final)->keys()->all(),
        ];

        $this->writeOrder($ids, $stages);
    }

    /** @return Collection<int, FactoryStage> */
    private function group(FactoryStage $stage): Collection
    {
        return FactoryStage::query()
            ->ofPipeline($stage->pipeline_type)
            ->where('is_final', $stage->is_final)
            ->ordered()
            ->get();
    }

    /** @param list<int> $groupIds */
    private function applyGroupOrder(PipelineType $pipeline, bool $final, array $groupIds): void
    {
        $stages = FactoryStage::query()->ofPipeline($pipeline)->ordered()->get()->keyBy('id');
        $otherGroup = $stages->filter(fn (FactoryStage $s): bool => $s->is_final !== $final)->keys()->all();

        $this->writeOrder($final ? [...$otherGroup, ...$groupIds] : [...$groupIds, ...$otherGroup], $stages);
    }

    /**
     * @param  list<int>  $ids
     * @param  \Illuminate\Support\Collection<int, FactoryStage>  $stages
     */
    private function writeOrder(array $ids, \Illuminate\Support\Collection $stages): void
    {
        $initialId = collect($ids)->first(
            fn (int $id): bool => ! $stages[$id]->is_final && $stages[$id]->is_active,
        );

        foreach ($ids as $index => $id) {
            FactoryStage::query()->whereKey($id)->update([
                'order' => ($index + 1) * 10,
                'is_initial' => $id === $initialId,
            ]);
        }
    }

    private function activeWorkingCount(PipelineType $pipeline, FactoryStage $except): int
    {
        return FactoryStage::query()
            ->ofPipeline($pipeline)
            ->where('is_final', false)
            ->where('is_active', true)
            ->whereKeyNot($except->id)
            ->count();
    }

    private function validName(PipelineType $pipeline, string $name, ?FactoryStage $ignore = null): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if (mb_strlen($name) < 2) {
            throw PipelineException::nameTooShort();
        }

        if (mb_strlen($name) > self::NAME_MAX) {
            throw PipelineException::nameTooLong(self::NAME_MAX);
        }

        $taken = FactoryStage::query()
            ->ofPipeline($pipeline)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->pluck('name')
            ->contains(fn (string $existing): bool => mb_strtolower($existing) === mb_strtolower($name));

        if ($taken) {
            throw PipelineException::duplicateName($name);
        }

        return $name;
    }

    private function uniqueCode(PipelineType $pipeline, string $name): string
    {
        $base = Str::limit(Str::slug($name, '_'), 50, '') ?: 'stage';
        $code = $base;

        for ($suffix = 2; FactoryStage::query()->ofPipeline($pipeline)->where('code', $code)->exists(); $suffix++) {
            $code = "{$base}_{$suffix}";
        }

        return $code;
    }
}
