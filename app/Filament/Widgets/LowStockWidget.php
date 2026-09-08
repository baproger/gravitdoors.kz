<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\MaterialStock;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** Материалы, дошедшие до минимального остатка, — чтобы цех не встал на середине наряда. */
class LowStockWidget extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Заканчиваются материалы')
            ->description('Остаток опустился до минимального порога')
            ->query(fn (): Builder => MaterialStock::query()->where('is_active', true)->belowLimit()->orderBy('name'))
            ->columns([
                TextColumn::make('name')->label('Материал')->weight('semibold')->wrap(),

                TextColumn::make('quantity')
                    ->label('Остаток')
                    ->state(fn (MaterialStock $record): string => rtrim(rtrim((string) $record->quantity, '0'), '.')
                        .' '.$record->unit->getLabel())
                    ->badge()
                    ->color('danger')
                    ->alignEnd(),

                TextColumn::make('min_limit')
                    ->label('Минимум')
                    ->formatStateUsing(fn ($state): string => rtrim(rtrim((string) $state, '0'), '.'))
                    ->color('gray')
                    ->alignEnd(),

                TextColumn::make('supplier')->label('Поставщик')->placeholder('—'),
            ])
            ->paginated([5])
            ->emptyStateHeading('Складских дефицитов нет')
            ->emptyStateDescription('Все позиции выше минимального остатка.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
