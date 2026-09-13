<?php

declare(strict_types=1);

namespace App\Filament\Resources\DoorOptions;

use App\Filament\Resources\DoorOptions\Pages\PriceList;
use App\Models\DoorOption;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Прайс конфигуратора: отсюда калькулятор берёт все цены.
 *
 * Одна страница — bento-сетка по группам опций. Правила целостности прайса
 * живут в PriceListService.
 */
class DoorOptionResource extends Resource
{
    protected static ?string $model = DoorOption::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?string $navigationLabel = 'Прайс конфигуратора';

    protected static ?string $modelLabel = 'позиция прайса';

    protected static ?string $pluralModelLabel = 'Прайс конфигуратора';

    protected static ?string $recordTitleAttribute = 'label';

    protected static ?int $navigationSort = 20;

    public static function getPages(): array
    {
        return ['index' => PriceList::route('/')];
    }
}
