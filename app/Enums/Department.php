<?php

declare(strict_types=1);

namespace App\Enums;

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
    case System = 'system';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sales => 'Отдел продаж',
            self::Survey => 'Замеры',
            self::Factory => 'Завод',
            self::Warehouse => 'Склад',
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
            self::System => 'heroicon-o-bolt',
        };
    }

    /** Действие без пользователя — это автоматика системы. */
    public static function forRole(?UserRole $role): self
    {
        return match ($role) {
            UserRole::Admin, UserRole::Manager => self::Sales,
            UserRole::Surveyor => self::Survey,
            UserRole::Master, UserRole::Worker => self::Factory,
            default => self::System,
        };
    }
}
