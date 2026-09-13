<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\DoorOption;
use App\Support\Plural;
use DomainException;

/** Правка прайса, которая сломала бы расчёт уже оформленных заказов. Текст показывается как есть. */
class PriceListException extends DomainException
{
    public static function unknownCategory(): self
    {
        return new self('Выберите группу прайса.');
    }

    public static function labelTooShort(): self
    {
        return new self('Название позиции — минимум 2 символа.');
    }

    public static function labelTooLong(): self
    {
        return new self('Название позиции — не длиннее 255 символов.');
    }

    public static function duplicateLabel(string $label, string $group): self
    {
        return new self("В группе «{$group}» уже есть позиция «{$label}».");
    }

    public static function negativePrice(): self
    {
        return new self('Цена не может быть отрицательной.');
    }

    public static function unknownPriceType(): self
    {
        return new self('Выберите, как считается цена.');
    }

    public static function negativeConsumption(): self
    {
        return new self('Расход материала не может быть отрицательным.');
    }

    public static function unknownMaterial(): self
    {
        return new self('Такого материала на складе нет.');
    }

    public static function additionalHasNoDefault(): self
    {
        return new self('Доп. опции выбираются списком — варианта по умолчанию у них нет.');
    }

    public static function inactiveCannotBeDefault(DoorOption $option): self
    {
        return new self("«{$option->label}» снята с продажи — сначала верните её в продажу.");
    }

    public static function inUse(DoorOption $option, int $doors): self
    {
        return new self(
            "«{$option->label}» выбрана в {$doors} ".Plural::choose($doors, 'двери', 'дверях', 'дверях')
            .'. Удалить нельзя — у этих заказов пропала бы часть цены. Снимите позицию с продажи переключателем.'
        );
    }
}
