<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Итог по лоту: лоты одного тендера выигрываются и проигрываются по отдельности. */
enum TenderLotResult: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Won = 'won';
    case Lost = 'lost';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Ждём итогов',
            self::Won => 'Выиграли',
            self::Lost => 'Проиграли',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Won => 'success',
            self::Lost => 'danger',
        };
    }
}
