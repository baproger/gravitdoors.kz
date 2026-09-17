<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bonuses;

use App\Filament\Resources\Bonuses\Pages\ManageBonuses;
use App\Filament\Resources\Bonuses\Schemas\BonusForm;
use App\Filament\Resources\Bonuses\Tables\BonusesTable;
use App\Models\Bonus;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BonusResource extends Resource
{
    protected static ?string $model = Bonus::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|UnitEnum|null $navigationGroup = 'Финансы';

    protected static ?string $navigationLabel = 'Бонусы';

    protected static ?string $modelLabel = 'бонус';

    protected static ?string $pluralModelLabel = 'Бонусы';

    protected static ?string $recordTitleAttribute = 'reason';

    protected static ?int $navigationSort = 80;

    protected static ?string $slug = 'bonuses';

    public static function form(Schema $schema): Schema
    {
        return BonusForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BonusesTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ManageBonuses::route('/')];
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Bonus::query()->where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
