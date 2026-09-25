<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Где тендер в работе B2B: от найденной закупки до итогов.
 *
 * «Выиграли» и «Проиграли» ставятся сами по результатам лотов
 * (`TenderService::syncStatus`) — руками их ставить незачем.
 */
enum TenderStatus: string implements HasColor, HasLabel
{
    case New = 'new';
    case Preparing = 'preparing';
    case Submitted = 'submitted';
    case Won = 'won';
    case Lost = 'lost';
    case Declined = 'declined';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'Найден',
            self::Preparing => 'Готовим заявку',
            self::Submitted => 'Заявка подана',
            self::Won => 'Выиграли',
            self::Lost => 'Проиграли',
            self::Declined => 'Не участвуем',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'gray',
            self::Preparing => 'info',
            self::Submitted => 'warning',
            self::Won => 'success',
            self::Lost => 'danger',
            self::Declined => 'gray',
        };
    }

    /** Тендер ещё в работе: срок подачи и итоги впереди. */
    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    /** Срок подачи важен, пока заявка не подана: после подачи он уже прошёл для нас. */
    public function awaitsSubmission(): bool
    {
        return in_array($this, [self::New, self::Preparing], true);
    }

    /** @return list<self> */
    public static function active(): array
    {
        return [self::New, self::Preparing, self::Submitted];
    }
}
