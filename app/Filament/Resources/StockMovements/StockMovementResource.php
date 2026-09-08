<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockMovements;

use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\StockMovements\Tables\StockMovementsTable;
use App\Models\MaterialStock;
use App\Models\StockMovement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Журнал движений склада — только чтение.
 *
 * Остаток material_stocks.quantity производен от этих строк, поэтому править
 * их руками нельзя: приход оформляется действием «Приход», расход появляется
 * при передаче наряда в цех, возврат — при отмене наряда.
 */
class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-up-down';

    protected static string|UnitEnum|null $navigationGroup = 'Работа';

    protected static ?string $navigationLabel = 'Движения склада';

    protected static ?string $modelLabel = 'движение';

    protected static ?string $pluralModelLabel = 'Движения склада';

    protected static ?int $navigationSort = 50;

    public static function table(Table $table): Table
    {
        return StockMovementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListStockMovements::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['materialStock', 'deal', 'user']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', MaterialStock::class) ?? false;
    }
}
