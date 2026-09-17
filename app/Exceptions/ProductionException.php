<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\PipelineType;
use App\Enums\StageRequirement;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\MaterialStock;
use DomainException;

/** Нарушение правил движения сделки по воронкам. */
class ProductionException extends DomainException
{
    public static function wrongPipeline(Deal $deal, FactoryStage $stage): self
    {
        return new self(sprintf(
            'Этап «%s» принадлежит воронке «%s», а сделка %s — воронке «%s».',
            $stage->name,
            $stage->pipeline_type->getLabel(),
            $deal->number,
            $deal->pipeline_type->getLabel(),
        ));
    }

    public static function dealClosed(Deal $deal): self
    {
        return new self("Сделка {$deal->number} закрыта ({$deal->status_id->getLabel()}) — движение по воронке недоступно.");
    }

    public static function noFactoryStages(): self
    {
        return new self('В воронке завода нет ни одного активного этапа. Настройте этапы в разделе «Этапы воронок».');
    }

    public static function notASalesDeal(Deal $deal): self
    {
        return new self("{$deal->number} — это наряд завода, а не сделка продаж. Наряд отменяется кнопкой «Отменить наряд».");
    }

    public static function notAFactoryOrder(Deal $deal): self
    {
        return new self("Сделка {$deal->number} не является производственным нарядом.");
    }

    public static function noConfiguration(Deal $deal): self
    {
        return new self("У сделки {$deal->number} не заполнена спецификация двери — нечего передавать в цех.");
    }

    /** Перепрыгивание этапов: сделка должна идти по воронке подряд. */
    public static function stageSkipped(Deal $deal, FactoryStage $stage, FactoryStage $expected): self
    {
        return new self(sprintf(
            'Нельзя перепрыгнуть на «%s»: следующий этап — «%s». Сделка должна двигаться по воронке подряд.',
            $stage->name,
            $expected->name,
        ));
    }

    /**
     * @param  list<StageRequirement>  $missing
     */
    public static function requirementsNotMet(FactoryStage $stage, array $missing): self
    {
        $list = collect($missing)
            ->map(fn ($requirement): string => '• '.$requirement->getLabel().' — '.$requirement->hint())
            ->implode("\n");

        return new self("Для этапа «{$stage->name}» не хватает данных:\n{$list}");
    }

    public static function waitingForFactory(Deal $deal, Deal $order): self
    {
        $stage = $order->currentStage?->name;
        $finish = FactoryStage::query()
            ->ofPipeline(PipelineType::Factory)
            ->where('completes_production', true)
            ->value('name');

        return new self(
            "Сделка {$deal->number} ждёт завод: наряд {$order->number}"
            .($stage ? " сейчас на этапе «{$stage}»" : '')
            .'. Дальше её переведёт производство'
            .($finish ? " после «{$finish}»" : ' после завершения наряда')
            .'. Если заказ отменили — сначала отмените наряд в списке сделок.'
        );
    }

    public static function insufficientStock(MaterialStock $material, float $required): self
    {
        return new self(sprintf(
            'Недостаточно материала «%s»: нужно %s %s, на складе %s.',
            $material->name,
            rtrim(rtrim(number_format($required, 3, '.', ' '), '0'), '.'),
            $material->unit->getLabel(),
            rtrim(rtrim((string) $material->quantity, '0'), '.'),
        ));
    }
}
