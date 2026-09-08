<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OpeningSide: string implements HasLabel
{
    case Left = 'left';
    case Right = 'right';

    public function getLabel(): string
    {
        return match ($this) {
            self::Left => 'Левое (петли слева)',
            self::Right => 'Правое (петли справа)',
        };
    }
}
