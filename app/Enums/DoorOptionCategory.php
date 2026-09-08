<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Группы прайс-листа конфигуратора. Каждая группа соответствует одному полю
 * door_configurations, поэтому калькулятор собирает цену, не зная ни одного
 * конкретного артикула — всё берётся из таблицы door_options.
 */
enum DoorOptionCategory: string implements HasLabel
{
    case MetalThickness = 'metal_thickness';
    case OuterMdfPanel = 'outer_mdf_panel';
    case InnerMdfPanel = 'inner_mdf_panel';
    case LockSystem = 'lock_system';
    case InsulationType = 'insulation_type';
    case ColorCoating = 'color_coating';
    case Additional = 'additional';

    public function getLabel(): string
    {
        return match ($this) {
            self::MetalThickness => 'Толщина металла',
            self::OuterMdfPanel => 'Наружная МДФ-панель',
            self::InnerMdfPanel => 'Внутренняя МДФ-панель',
            self::LockSystem => 'Замковая система',
            self::InsulationType => 'Утеплитель',
            self::ColorCoating => 'Цвет / покрытие',
            self::Additional => 'Доп. опции',
        };
    }

    /** Колонка door_configurations, которая хранит выбор из этой группы. */
    public function configurationColumn(): ?string
    {
        return $this === self::Additional ? null : $this->value;
    }

    /**
     * Имя поля в форме. Совпадает с кодом группы везде, кроме доп. опций:
     * они хранятся в колонке additional_options и выбираются списком.
     */
    public function formField(): string
    {
        return $this === self::Additional ? 'additional_options' : $this->value;
    }
}
