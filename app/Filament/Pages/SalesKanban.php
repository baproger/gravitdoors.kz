<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Enums\PipelineType;
use App\Services\AccessControl;
use App\Support\Filament\KanbanBoardPage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class SalesKanban extends KanbanBoardPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|UnitEnum|null $navigationGroup = 'Работа';

    protected static ?string $navigationLabel = 'Воронка продаж';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'kanban/sales';

    public static function pipeline(): PipelineType
    {
        return PipelineType::Sales;
    }

    /** Воронка продаж цеху не нужна и не положена. */
    public static function canAccess(): bool
    {
        return AccessControl::can(Permission::WorkSalesKanban);
    }

    public function getTitle(): string
    {
        return 'Воронка продаж';
    }

    public function getSubheading(): ?string
    {
        return 'Двигайте сделку кнопками «← →» на карточке или перетаскиванием. На этапе «Передано в производство» наряд на заводе откроется сам.';
    }
}
