<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Модель двери из каталога Gravit. */
enum DoorModel: string implements HasLabel
{
    case Agora = 'agora';
    case Azhur = 'azhur';
    case Lion = 'lion';
    case Montana = 'montana';

    public function getLabel(): string
    {
        return match ($this) {
            self::Agora => 'Агора',
            self::Azhur => 'Ажур',
            self::Lion => 'Лион',
            self::Montana => 'Монтана',
        };
    }
}
