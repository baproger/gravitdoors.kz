<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Как цена опции превращается в деньги при расчёте конкретной двери. */
enum PriceType: string implements HasLabel
{
    /** Фиксированная сумма за изделие (замок, доводчик). */
    case Fixed = 'fixed';
    /** Цена за м² полотна — габариты двери умножают стоимость (металл, МДФ, утеплитель). */
    case PerSquareMeter = 'per_sqm';
    /** Цена за погонный метр периметра (уплотнитель, наличник). */
    case PerMeter = 'per_meter';

    public function getLabel(): string
    {
        return match ($this) {
            self::Fixed => 'Фикс. за изделие',
            self::PerSquareMeter => 'За м²',
            self::PerMeter => 'За м.п. периметра',
        };
    }
}
