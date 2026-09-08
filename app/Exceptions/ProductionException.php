<?php

declare(strict_types=1);

namespace App\Exceptions;

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

    public static function notAFactoryOrder(Deal $deal): self
    {
        return new self("Сделка {$deal->number} не является производственным нарядом.");
    }

    public static function noConfiguration(Deal $deal): self
    {
        return new self("У сделки {$deal->number} не заполнена спецификация двери — нечего передавать в цех.");
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
