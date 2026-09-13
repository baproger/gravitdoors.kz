<?php

declare(strict_types=1);

namespace App\Filament\Resources\FactoryStages;

use App\Filament\Resources\FactoryStages\Pages\PipelineStages;
use App\Models\FactoryStage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Настройка воронок продаж и завода.
 *
 * У ресурса одна страница — конструктор воронок. Таблица и форма со
 * служебными полями «Порядок» и «Код» убраны: порядок задаётся
 * перетаскиванием, код генерируется сам, а правила целостности живут
 * в PipelineStageService.
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

    public static function getPages(): array
    {
        return [
            'index' => PipelineStages::route('/'),
        ];
    }
}
