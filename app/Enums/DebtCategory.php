<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DebtCategory: string implements HasLabel
{
    case Supplier = 'supplier';
    case Rent = 'rent';
    case Tax = 'tax';
    case Loan = 'loan';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Supplier => 'Поставщик',
            self::Rent => 'Аренда',
            self::Tax => 'Налоги',
            self::Loan => 'Займ / кредит',
            self::Other => 'Прочее',
        };
    }

    /** В какую категорию расходов ложится платёж по долгу. */
    public function expenseCategory(): ExpenseCategory
    {
        return match ($this) {
            self::Supplier => ExpenseCategory::Materials,
            self::Rent => ExpenseCategory::Rent,
            self::Tax => ExpenseCategory::Tax,
            self::Loan, self::Other => ExpenseCategory::Other,
        };
    }
}
