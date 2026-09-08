<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasColor, HasLabel
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Master = 'master';
    case Worker = 'worker';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Администратор',
            self::Manager => 'Менеджер',
            self::Master => 'Мастер цеха',
            self::Worker => 'Рабочий',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Admin => 'danger',
            self::Manager => 'info',
            self::Master => 'warning',
            self::Worker => 'gray',
        };
    }

    /** Видит ли роль деньги сделки (себестоимость, маржу). */
    public function seesMoney(): bool
    {
        return in_array($this, [self::Admin, self::Manager], true);
    }
}
