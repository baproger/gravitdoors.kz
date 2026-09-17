<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CashAccountType: string implements HasLabel
{
    case Cash = 'cash';
    case Bank = 'bank';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => 'Касса (наличные)',
            self::Bank => 'Банк (безнал)',
        };
    }

    /** Наличные идут в кассу, всё остальное (карта, Kaspi, перевод) — в банк. */
    public static function forMethod(PaymentMethod $method): self
    {
        return $method === PaymentMethod::Cash ? self::Cash : self::Bank;
    }
}
