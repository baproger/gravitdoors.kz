<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaterialStocks;

use App\Filament\Resources\MaterialStocks\Pages\ManageMaterialStocks;
use App\Filament\Resources\MaterialStocks\Schemas\MaterialStockForm;
use App\Filament\Resources\MaterialStocks\Tables\MaterialStocksTable;
use App\Models\MaterialStock;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class MaterialStockResource extends Resource
{
    protected static ?string $model = MaterialStock::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Работа';

    protected static ?string $navigationLabel = 'Склад материалов';

    protected static ?string $modelLabel = 'материал';

    protected static ?string $pluralModelLabel = 'Склад материалов';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return MaterialStockForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MaterialStocksTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMaterialStocks::route('/')];
    }

    /** Бейдж-сигнал: сколько позиций опустилось до минимального остатка. */
    public static function getNavigationBadge(): ?string
    {
        $count = MaterialStock::query()->where('is_active', true)->belowLimit()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }
}
