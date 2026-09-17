<?php

declare(strict_types=1);

namespace App\Filament\Resources\Debts;

use App\Filament\Resources\Debts\Pages\ManageDebts;
use App\Filament\Resources\Debts\Schemas\DebtForm;
use App\Filament\Resources\Debts\Tables\DebtsTable;
use App\Models\Debt;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DebtResource extends Resource
{
    protected static ?string $model = Debt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Финансы';

    protected static ?string $navigationLabel = 'Задолженности';

    protected static ?string $modelLabel = 'долг';

    protected static ?string $pluralModelLabel = 'Задолженности';

    protected static ?string $recordTitleAttribute = 'counterparty';

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'debts';

    public static function form(Schema $schema): Schema
    {
        return DebtForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DebtsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ManageDebts::route('/')];
    }

    /** Просроченные долги — красным в меню. */
    public static function getNavigationBadge(): ?string
    {
        $count = Debt::query()->open()->whereDate('due_at', '<', today())->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }
}
