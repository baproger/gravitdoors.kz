<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tenders;

use App\Filament\Resources\Tenders\Pages\CreateTender;
use App\Filament\Resources\Tenders\Pages\EditTender;
use App\Filament\Resources\Tenders\Pages\ListTenders;
use App\Filament\Resources\Tenders\Pages\ViewTender;
use App\Filament\Resources\Tenders\RelationManagers\LotsRelationManager;
use App\Filament\Resources\Tenders\Schemas\TenderForm;
use App\Filament\Resources\Tenders\Tables\TendersTable;
use App\Models\Tender;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Тендеры B2B: закупка, документы заявки, лоты и итоги.
 *
 * Из выигранного лота кнопкой заводится сделка — дальше она идёт по обычной
 * воронке продаж, как любая другая.
 */
class TenderResource extends Resource
{
    protected static ?string $model = Tender::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static string|UnitEnum|null $navigationGroup = 'Работа';

    protected static ?string $navigationLabel = 'Тендеры';

    protected static ?string $modelLabel = 'тендер';

    protected static ?string $pluralModelLabel = 'Тендеры';

    protected static ?string $recordTitleAttribute = 'title';

    // Сразу за сделками: тендер — источник сделок B2B.
    protected static ?int $navigationSort = 35;

    /** @return list<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['announcement_number', 'title', 'customer_name', 'customer_bin'];
    }

    public static function form(Schema $schema): Schema
    {
        return TenderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TendersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [LotsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenders::route('/'),
            'create' => CreateTender::route('/create'),
            'view' => ViewTender::route('/{record}'),
            'edit' => EditTender::route('/{record}/edit'),
        ];
    }

    /**
     * Ограничение на запросе — действует и в списке, и в поиске, и по прямой ссылке.
     *
     * @return Builder<Tender>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Tender> $query */
        $query = parent::getEloquentQuery();

        return $query->with('manager')->visibleTo(auth()->user());
    }

    public static function cardUrl(Tender $record): string
    {
        return auth()->user()?->can('update', $record)
            ? static::getUrl('edit', ['record' => $record])
            : static::getUrl('view', ['record' => $record]);
    }

    /** Заявки, которые надо подать в ближайшие дни, — красным в меню. */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->deadlineSoon()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Приём заявок закрывается в ближайшие '.Tender::DEADLINE_WARNING_DAYS.' дня';
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails($record): array
    {
        return [
            'Заказчик' => $record->customer_name,
            'Статус' => $record->status->getLabel(),
        ];
    }
}
