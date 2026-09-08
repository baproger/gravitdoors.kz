<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\RelationManagers;

use App\Enums\DealEventType;
use App\Enums\Department;
use App\Models\DealEvent;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * История сделки: кто, из какого отдела и что сделал.
 *
 * Отдел показывается тот, что записан в момент события, — если сотрудник
 * сменил должность, лента не должна переписываться задним числом.
 */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    // Блок грузится сразу: ленивая подгрузка оставляла на странице
    // бесконечное «Loading…», а история нужна вместе с карточкой.
    protected static bool $isLazy = false;

    protected static ?string $title = 'История сделки';

    protected static \BackedEnum|string|null $icon = 'heroicon-o-clock';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('created_at')
                    ->label('Когда')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->size('sm'),

                TextColumn::make('department')
                    ->label('Отдел')
                    ->badge()
                    ->icon(fn (DealEvent $record): string => $record->department->getIcon()),

                TextColumn::make('user.name')
                    ->label('Кто')
                    ->placeholder('Система')
                    ->weight('semibold')
                    ->searchable()
                    ->visibleFrom('md'),

                TextColumn::make('type')
                    ->label('Событие')
                    ->badge()
                    ->icon(fn (DealEvent $record): string => $record->type->getIcon())
                    ->visibleFrom('lg'),

                TextColumn::make('description')
                    ->label('Что произошло')
                    ->description(fn (DealEvent $record): ?string => self::changesSummary($record))
                    ->wrap()
                    ->searchable(),
            ])
            ->filters([
                SelectFilter::make('department')
                    ->label('Отдел')
                    ->options(Department::class)
                    ->multiple(),

                SelectFilter::make('type')
                    ->label('Тип события')
                    ->options(DealEventType::class)
                    ->multiple(),
            ])
            ->emptyStateHeading('Событий пока нет')
            ->emptyStateDescription('Здесь появится всё, что происходило со сделкой.')
            ->emptyStateIcon('heroicon-o-clock');
    }

    /** Что именно поменяли — «Телефон: — → +7 (707) …». */
    private static function changesSummary(DealEvent $record): ?string
    {
        if (blank($record->changes)) {
            return null;
        }

        return collect($record->changes)
            ->take(6)
            ->map(fn (array $change): string => "{$change['label']}: {$change['from']} → {$change['to']}")
            ->implode("\n");
    }
}
