<?php

declare(strict_types=1);

namespace App\Filament\Resources\FactoryStages;

use App\Filament\Resources\FactoryStages\Pages\ManageFactoryStages;
use App\Filament\Resources\FactoryStages\Schemas\FactoryStageForm;
use App\Filament\Resources\FactoryStages\Tables\FactoryStagesTable;
use App\Models\FactoryStage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Конструктор воронок: добавление, сортировка и переименование этапов
 * обеих воронок без правки кода. Здесь же включаются флаги автоматизации
 * «передать в производство» и «завершает производство».
 */
class FactoryStageResource extends Resource
{
    protected static ?string $model = FactoryStage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?string $navigationLabel = 'Этапы воронок';

    protected static ?string $modelLabel = 'этап';

    protected static ?string $pluralModelLabel = 'Этапы воронок';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return FactoryStageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FactoryStagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFactoryStages::route('/'),
        ];
    }
}
