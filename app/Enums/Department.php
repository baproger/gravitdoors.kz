<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Role;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/** Отдел, от лица которого произошло событие в истории сделки. */
enum Department: string implements HasColor, HasIcon, HasLabel
{
    case Sales = 'sales';
    case Survey = 'survey';
    case Factory = 'factory';
    case Warehouse = 'warehouse';
    case Finance = 'finance';
    case Hr = 'hr';
    case System = 'system';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sales => 'Отдел продаж',
            self::Survey => 'Замеры',
            self::Factory => 'Завод',
            self::Warehouse => 'Склад',
            self::Finance => 'Финансы',
            self::Hr => 'Кадры',
            self::System => 'Система',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Sales => 'info',
            self::Survey => 'success',
            self::Factory => 'warning',
            self::Warehouse => 'gray',
            self::Finance => 'primary',
            self::Hr => 'violet',
            self::System => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Sales => 'heroicon-o-briefcase',
            self::Survey => 'heroicon-o-map-pin',
            self::Factory => 'heroicon-o-cog-6-tooth',
            self::Warehouse => 'heroicon-o-archive-box',
            self::Finance => 'heroicon-o-banknotes',
            self::Hr => 'heroicon-o-identification',
            self::System => 'heroicon-o-bolt',
        };
    }

    /** Действие без пользователя — это автоматика системы. */
    /**
     * Каким отделом подписаны действия роли в истории сделки.
     *
     * По коду, а не по модели роли: у семи базовых ролей отдел известен, а
     * придуманная в панели роль подписывается «Системой» — отдельного поля у
     * неё нет, и выдумывать отдел за владельца система не станет.
     */
    public static function forRole(Role|UserRole|string|null $role): self
    {
        $code = match (true) {
            $role instanceof Role => $role->code,
            $role instanceof UserRole => $role->value,
            default => (string) $role,
        };

        return match ($code) {
            UserRole::Admin->value, UserRole::Manager->value, UserRole::B2b->value => self::Sales,
            UserRole::Accountant->value => self::Finance,
            UserRole::Hr->value => self::Hr,
            UserRole::Surveyor->value => self::Survey,
            UserRole::Master->value, UserRole::Worker->value => self::Factory,
            default => self::System,
        };
    }
}
