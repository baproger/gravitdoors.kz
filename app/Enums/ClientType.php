<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ClientType: string implements HasIcon, HasLabel
{
    case Individual = 'individual';
    case Company = 'company';

    public function getLabel(): string
    {
        return match ($this) {
            self::Individual => 'Физлицо',
            self::Company => 'Компания',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Individual => 'heroicon-o-user',
            self::Company => 'heroicon-o-building-office-2',
        };
    }
}
