<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Где объявлена закупка — от площадки зависят правила подачи и документы. */
enum TenderPlatform: string implements HasLabel
{
    case Goszakup = 'goszakup';
    case Samruk = 'samruk';
    case Commercial = 'commercial';
    case Direct = 'direct';

    public function getLabel(): string
    {
        return match ($this) {
            self::Goszakup => 'Госзакуп (goszakup.gov.kz)',
            self::Samruk => 'Самрук-Казына',
            self::Commercial => 'Коммерческая площадка',
            self::Direct => 'Запрос от заказчика напрямую',
        };
    }
}
