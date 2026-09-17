<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals;

use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Deals\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Deals\RelationManagers\ProductionLogsRelationManager;
use App\Filament\Resources\Deals\Schemas\DealForm;
use App\Filament\Resources\Deals\Tables\DealsTable;
use App\Models\Deal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class DealResource extends Resource
{
    protected static ?string $model = Deal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Работа';

    protected static ?string $navigationLabel = 'Сделки и наряды';

    protected static ?string $modelLabel = 'сделка';

    protected static ?string $pluralModelLabel = 'Сделки';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 30;

    /**
     * Глобальный поиск по номеру, клиенту и телефону — не только по названию.
     *
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'title', 'client_name', 'client_phone'];
    }

    public static function form(Schema $schema): Schema
    {
        return DealForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DealsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            EventsRelationManager::class,
            ProductionLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeals::route('/'),
            'create' => CreateDeal::route('/create'),
            'edit' => EditDeal::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['currentStage', 'manager']);

        // Мастер и рабочий работают с нарядами, а не со сделками отдела продаж:
        // ограничение стоит на запросе, поэтому действует и в списке, и в поиске.
        if (! auth()->user()?->role->seesMoney()) {
            $query->factoryOrders();
        }

        return $query;
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getNavigationBadge(): ?string
    {
        // Считаем по запросу ресурса, а не по всей таблице: иначе бейдж у мастера
        // показывал бы сделки, которых он в списке всё равно не увидит.
        return (string) static::getEloquentQuery()->open()->count();
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails($record): array
    {
        return [
            'Клиент' => $record->client_name,
            'Этап' => $record->currentStage?->name ?? '—',
        ];
    }
}
