<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\PipelineType;
use App\Enums\UserRole;
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

    /** Канбан завода — инструмент цеха и администратора; продажи ход работ видят в карточке сделки. */
    public static function canAccess(): bool
    {
        $role = auth()->user()?->role;

        return $role === UserRole::Admin || $role === UserRole::Master || $role === UserRole::Worker;
    }

    public function getTitle(): string
    {
        return 'Завод / Производство';
    }

    public function getSubheading(): ?string
    {
        return 'Двигайте наряд кнопками «← →» или перетаскиванием. На последнем этапе нажмите «Готово ✓» — наряд закроется, а сделка продаж перейдёт в «Готово к отгрузке».';
    }
}
