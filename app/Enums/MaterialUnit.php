<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MaterialUnit: string implements HasLabel
{
    case Sheets = 'sheets';
    case Meters = 'meters';
    case SquareMeters = 'sq_meters';
    case Pieces = 'pcs';
    case Kilograms = 'kg';
    case Liters = 'liters';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sheets => 'лист',
            self::Meters => 'м.п.',
            self::SquareMeters => 'м²',
            self::Pieces => 'шт',
            self::Kilograms => 'кг',
            self::Liters => 'л',
        };
    }
}
