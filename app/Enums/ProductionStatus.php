<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Состояние одной записи production_logs — прохождения наряда через этап цеха. */
enum ProductionStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Paused = 'paused';
    case Done = 'done';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Ожидает',
            self::InProgress => 'В работе',
            self::Paused => 'Пауза',
            self::Done => 'Выполнено',
            self::Rejected => 'Брак / возврат',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::InProgress => 'info',
            self::Paused => 'warning',
            self::Done => 'success',
            self::Rejected => 'danger',
        };
    }
}
