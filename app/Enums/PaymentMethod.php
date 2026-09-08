<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasLabel
{
    case Cash = 'cash';
    case Card = 'card';
    case Kaspi = 'kaspi';
    case Transfer = 'transfer';
    case Installment = 'installment';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => 'Наличные',
            self::Card => 'Карта',
            self::Kaspi => 'Kaspi перевод',
            self::Transfer => 'Счёт / безнал',
            self::Installment => 'Рассрочка',
        };
    }
}
