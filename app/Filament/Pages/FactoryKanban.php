<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\PipelineType;
use App\Support\Filament\KanbanBoardPage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class FactoryKanban extends KanbanBoardPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Работа';

    protected static ?string $navigationLabel = 'Воронка завода';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'kanban/factory';

    public static function pipeline(): PipelineType
    {
        return PipelineType::Factory;
    }

    public function getTitle(): string
    {
        return 'Завод / Производство';
    }

    public function getSubheading(): ?string
    {
        return 'Закрытие этапа «ОТК и Упаковка» автоматически переводит сделку продаж в «Готово к отгрузке».';
    }
}
