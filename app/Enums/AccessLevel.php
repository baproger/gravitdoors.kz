<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Уровень доступа роли к разделу или действию.
 *
 * Уровни упорядочены по силе: каждый следующий включает предыдущий, поэтому
 * проверка «хватает ли прав» — это сравнение веса, а не перечисление случаев.
 */
enum AccessLevel: string implements HasColor, HasLabel
{
    case None = 'none';
    case Read = 'read';
    case Own = 'own';
    case Full = 'full';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'Нет',
            self::Read => 'Чтение',
            self::Own => 'Только свои',
            self::Full => 'Полный',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::None => 'gray',
            self::Read => 'info',
            self::Own => 'warning',
            self::Full => 'success',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::None => 'Раздел скрыт, прямая ссылка возвращает 403',
            self::Read => 'Видно, но без создания, правки и действий',
            self::Own => 'Видно и можно менять только свои записи',
            self::Full => 'Без ограничений',
        };
    }

    public function weight(): int
    {
        return match ($this) {
            self::None => 0,
            self::Read => 1,
            self::Own => 2,
            self::Full => 3,
        };
    }

    /** Хватает ли этого уровня для требуемого. */
    public function atLeast(self $required): bool
    {
        return $this->weight() >= $required->weight();
    }

    public function allows(): bool
    {
        return $this !== self::None;
    }

    public function isOwnOnly(): bool
    {
        return $this === self::Own;
    }

    /** Можно ли менять данные: «чтение» — нельзя. */
    public function canWrite(): bool
    {
        return $this->atLeast(self::Own);
    }
}
