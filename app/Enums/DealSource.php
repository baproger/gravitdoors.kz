<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Откуда пришёл клиент — нужно, чтобы понимать, какая реклама окупается. */
enum DealSource: string implements HasLabel
{
    case Site = 'site';
    case Instagram = 'instagram';
    case WhatsApp = 'whatsapp';
    case Call = 'call';
    case Recommendation = 'recommendation';
    case Showroom = 'showroom';
    case Dealer = 'dealer';
    case Tender = 'tender';
    case Repeat = 'repeat';

    public function getLabel(): string
    {
        return match ($this) {
            self::Site => 'Сайт',
            self::Instagram => 'Instagram',
            self::WhatsApp => 'WhatsApp',
            self::Call => 'Входящий звонок',
            self::Recommendation => 'Рекомендация',
            self::Showroom => 'Шоурум',
            self::Dealer => 'Дилер',
            self::Tender => 'Госзакуп / тендер',
            self::Repeat => 'Повторный клиент',
        };
    }
}
