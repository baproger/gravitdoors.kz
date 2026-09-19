<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals;

use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Deals\RelationManagers\ProductionLogsRelationManager;
use App\Filament\Resources\Deals\Schemas\DealForm;
use App\Filament\Resources\Deals\Tables\DealsTable;
use App\Models\Deal;
use BackedEnum;
use Filament\Actions\Action;
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
            'view' => ViewDeal::route('/{record}'),
            'edit' => EditDeal::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // Ограничение стоит на запросе, поэтому действует и в списке,
        // и в глобальном поиске, и по прямой ссылке на карточку.
        return parent::getEloquentQuery()
            ->with(['currentStage', 'manager'])
            ->visibleTo(auth()->user());
    }

    /**
     * Ссылка на карточку: правка — тем, кто может править, остальным — просмотр.
     * Одна точка, иначе часть ссылок в системе вела бы читателя на 403.
     */
    public static function cardUrl(Deal $record): string
    {
        return auth()->user()?->can('update', $record)
            ? static::getUrl('edit', ['record' => $record])
            : static::getUrl('view', ['record' => $record]);
    }

    /**
     * Кнопка «Открыть сделку» в уведомлении колокольчика.
     *
     * Всегда просмотр, а не `cardUrl()`: та выбирает экран по правам того, кто
     * сейчас в системе, а уведомление читает другой человек. Со страницы
     * просмотра «Редактировать» есть у всех, кому можно править.
     */
    public static function openAction(Deal $record): Action
    {
        return Action::make('open')
            ->label('Открыть сделку')
            ->url(static::getUrl('view', ['record' => $record]))
            ->markAsRead();
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
