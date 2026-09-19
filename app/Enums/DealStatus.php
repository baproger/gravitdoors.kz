<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Статус сделки. Int-backed, потому что в схеме колонка называется `status_id`:
 * значение остаётся числовым идентификатором, но живёт в коде как перечисление,
 * а не как строка-магия по всему проекту.
 *
 * Шаг 10 между значениями оставлен намеренно — новые статусы можно вставлять
 * между существующими без миграции данных.
 */
enum DealStatus: int implements HasColor, HasLabel
{
    case New = 10;
    case InWork = 20;
    case HandedToProduction = 30;
    case InProduction = 40;
    case ReadyToShip = 50;
    case Shipped = 60;
    case Installed = 70;
    case Completed = 80;
    case Cancelled = 90;

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'Новая',
            self::InWork => 'В работе',
            self::HandedToProduction => 'Передано в производство',
            self::InProduction => 'В производстве',
            self::ReadyToShip => 'Готово к отгрузке',
            self::Shipped => 'Отгружено',
            self::Installed => 'Установлено',
            self::Completed => 'Завершена',
            self::Cancelled => 'Отменена',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'gray',
            self::InWork => 'info',
            self::HandedToProduction, self::InProduction => 'warning',
            self::ReadyToShip => 'success',
            self::Shipped, self::Installed => 'primary',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }

    /** Статусы, после которых сделку уже нельзя отправить в производство повторно. */
    public function isClosed(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    /**
     * Значения закрытых статусов для запросов.
     *
     * Один список на всю систему: раньше каждый скоуп перечислял их заново,
     * и новый закрытый статус пришлось бы добавлять в трёх местах — где-нибудь
     * да забылось бы, и сделка осталась бы висеть в рабочих вкладках.
     *
     * @return list<int>
     */
    public static function closedValues(): array
    {
        return array_values(array_map(
            fn (self $status): int => $status->value,
            array_filter(self::cases(), fn (self $status): bool => $status->isClosed()),
        ));
    }

    /** Что клиент видит на публичной странице /track/{hash}. */
    public function publicLabel(): string
    {
        return match ($this) {
            self::New, self::InWork => 'Заказ принят, готовим спецификацию',
            self::HandedToProduction => 'Заказ передан на завод',
            self::InProduction => 'Дверь в производстве',
            self::ReadyToShip => 'Готово к отгрузке',
            self::Shipped => 'Передано в доставку',
            self::Installed => 'Установлено',
            self::Completed => 'Заказ завершён',
            self::Cancelled => 'Заказ отменён',
        };
    }
}
