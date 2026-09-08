<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasColor, HasLabel
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Surveyor = 'surveyor';
    case Master = 'master';
    case Worker = 'worker';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Администратор',
            self::Manager => 'Менеджер',
            self::Surveyor => 'Замерщик',
            self::Master => 'Мастер цеха',
            self::Worker => 'Рабочий',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Admin => 'danger',
            self::Manager => 'info',
            self::Surveyor => 'success',
            self::Master => 'warning',
            self::Worker => 'gray',
        };
    }

    /** Видит ли роль деньги сделки (суммы, маржу). */
    public function seesMoney(): bool
    {
        return in_array($this, [self::Admin, self::Manager], true);
    }

    /** Выезжает ли роль на замеры — им уходят уведомления о назначенной дате. */
    public function doesSurveys(): bool
    {
        return in_array($this, [self::Surveyor, self::Master], true);
    }
}
