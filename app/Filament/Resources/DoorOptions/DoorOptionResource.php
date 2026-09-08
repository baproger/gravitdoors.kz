<?php

declare(strict_types=1);

namespace App\Filament\Resources\DoorOptions;

use App\Filament\Resources\DoorOptions\Pages\ManageDoorOptions;
use App\Filament\Resources\DoorOptions\Schemas\DoorOptionForm;
use App\Filament\Resources\DoorOptions\Tables\DoorOptionsTable;
use App\Models\DoorOption;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Прайс конфигуратора: именно отсюда калькулятор берёт все цены. */
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

    public static function form(Schema $schema): Schema
    {
        return DoorOptionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DoorOptionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ManageDoorOptions::route('/')];
    }
}
