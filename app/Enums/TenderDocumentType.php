<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Что за документ приложен к тендеру.
 *
 * Тип, а не просто список файлов: к заявке их набирается десяток, и через
 * месяц «scan_0034.pdf» уже никто не отличит протокол от гарантии.
 */
enum TenderDocumentType: string implements HasLabel
{
    case Specification = 'specification';
    case Application = 'application';
    case Security = 'security';
    case Protocol = 'protocol';
    case Contract = 'contract';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Specification => 'Техспецификация заказчика',
            self::Application => 'Наша заявка',
            self::Security => 'Обеспечение / банковская гарантия',
            self::Protocol => 'Протокол итогов',
            self::Contract => 'Договор',
            self::Other => 'Прочее',
        };
    }
}
