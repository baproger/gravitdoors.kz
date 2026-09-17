<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\FactoryStage;
use App\Support\Plural;
use DomainException;

/** Правка воронки, которая сломала бы работу со сделками. Текст показывается администратору как есть. */
class PipelineException extends DomainException
{
    public static function nameTooShort(): self
    {
        return new self('Название этапа — минимум 2 символа.');
    }

    public static function nameTooLong(int $max): self
    {
        return new self("Название этапа — не длиннее {$max} символов.");
    }

    public static function duplicateName(string $name): self
    {
        return new self("Этап «{$name}» в этой воронке уже есть.");
    }

    public static function invalidColor(): self
    {
        return new self('Такого цвета нет в палитре.');
    }

    public static function otherPipeline(): self
    {
        return new self('Этап можно перенести только внутри своей воронки.');
    }

    public static function triggerCannotBeFinal(FactoryStage $stage): self
    {
        return new self("«{$stage->name}» передаёт сделку в производство — такой этап не может быть завершающим: после завода сделка ещё идёт на отгрузку.");
    }

    public static function needWorkingStage(): self
    {
        return new self('В воронке должен остаться хотя бы один рабочий этап.');
    }

    public static function automationStage(FactoryStage $stage): self
    {
        return new self("«{$stage->name}» запускает автоматику завода. Сначала назначьте её другому этапу — в меню «⋯» у нужного этапа.");
    }

    public static function hiddenAutomation(FactoryStage $stage): self
    {
        return new self("Сначала покажите этап «{$stage->name}» — скрытый этап не может запускать автоматику.");
    }

    public static function hasWorkshopHistory(FactoryStage $stage): self
    {
        return new self("На этапе «{$stage->name}» уже есть работы цеха — по ним считается зарплата. Удалить его нельзя, но можно скрыть из воронки.");
    }

    public static function chooseMoveTarget(FactoryStage $stage, int $count): self
    {
        return new self("На этапе «{$stage->name}» {$count} ".Plural::choose($count, 'сделка', 'сделки', 'сделок').'. Выберите, на какой этап их перенести.');
    }

    public static function automationRequired(FactoryStage $stage): self
    {
        return new self("Автоматику нельзя выключить: она нужна воронке. Чтобы убрать её с этапа «{$stage->name}», включите её на другом этапе.");
    }

    public static function moveToFinal(FactoryStage $stage): self
    {
        return new self("Этап «{$stage->name}» завершающий — переносить на него открытые сделки нельзя.");
    }

    public static function moveToHidden(FactoryStage $stage): self
    {
        return new self("Этап «{$stage->name}» скрыт — переносить на него сделки нельзя.");
    }

    public static function hideWithDeals(FactoryStage $stage, int $count): self
    {
        return new self("На этапе «{$stage->name}» {$count} ".Plural::choose($count, 'сделка', 'сделки', 'сделок').'. Скрыть можно только пустой этап — сначала переведите сделки дальше.');
    }

    public static function invalidHours(): self
    {
        return new self('Норматив — от 0 до 999 часов.');
    }

    public static function invalidCost(): self
    {
        return new self('Сдельная оплата не может быть отрицательной.');
    }
}
