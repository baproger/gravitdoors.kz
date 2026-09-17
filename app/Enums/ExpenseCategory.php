<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Категории расходов — строки блока «Расходы» на финансовом обзоре. */
enum ExpenseCategory: string implements HasColor, HasLabel
{
    case Rent = 'rent';
    case Tax = 'tax';
    case Salary = 'salary';
    case Materials = 'materials';
    case Transport = 'transport';
    case Marketing = 'marketing';
    case Software = 'software';
    case Utilities = 'utilities';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Rent => 'Аренда',
            self::Tax => 'Налоги',
            self::Salary => 'Зарплата',
            self::Materials => 'Материалы',
            self::Transport => 'Транспорт и ГСМ',
            self::Marketing => 'Реклама',
            self::Software => 'Сервисы и сайты',
            self::Utilities => 'Коммунальные и связь',
            self::Other => 'Прочее',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Rent, self::Utilities => 'info',
            self::Tax => 'warning',
            self::Salary => 'primary',
            self::Materials => 'gray',
            self::Transport => 'gray',
            self::Marketing, self::Software => 'success',
            self::Other => 'gray',
        };
    }

    /** Зарплата и налоги подтверждаются банком, чек к ним не прикладывают. */
    public function requiresReceipt(): bool
    {
        return ! in_array($this, [self::Salary, self::Tax], true);
    }
}
