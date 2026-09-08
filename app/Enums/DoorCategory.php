<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Линейка изделия. */
enum DoorCategory: string implements HasColor, HasLabel
{
    case Comfort = 'comfort';
    case Premium = 'premium';
    case Lux = 'lux';

    public function getLabel(): string
    {
        return match ($this) {
            self::Comfort => 'Comfort',
            self::Premium => 'Premium',
            self::Lux => 'Lux',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Comfort => 'gray',
            self::Premium => 'info',
            self::Lux => 'warning',
        };
    }
}
