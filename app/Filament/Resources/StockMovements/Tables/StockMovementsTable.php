<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockMovements\Tables;

use App\Models\StockMovement;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StockMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('materialStock.name')
                    ->label('Материал')
                    ->weight('semibold')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('type')
                    ->label('Операция')
                    ->badge()
                    // Приход по наряду — это возврат после отмены, а не закуп.
                    ->formatStateUsing(fn (string $state, StockMovement $record): string => match (true) {
                        $state !== StockMovement::TYPE_IN => 'Расход',
                        $record->deal_id !== null => 'Возврат',
                        default => 'Приход',
                    })
                    ->color(fn (string $state, StockMovement $record): string => match (true) {
                        $state !== StockMovement::TYPE_IN => 'warning',
                        $record->deal_id !== null => 'info',
                        default => 'success',
                    }),

                TextColumn::make('quantity')
                    ->label('Количество')
                    ->state(fn (StockMovement $record): string => ($record->type === StockMovement::TYPE_IN ? '+' : '−')
                        .rtrim(rtrim((string) $record->quantity, '0'), '.')
                        .' '.$record->materialStock?->unit->getLabel())
                    ->alignEnd(),

                TextColumn::make('total')
                    ->label('Сумма')
                    ->state(fn (StockMovement $record): string => Money::format($record->total()))
                    ->alignEnd()
                    ->visible(fn (): bool => auth()->user()?->role->seesMoney() ?? false),

                TextColumn::make('deal.number')
                    ->label('Наряд')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('user.name')->label('Кто')->placeholder('система')->toggleable(),

                TextColumn::make('comment')->label('Комментарий')->wrap()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Операция')
                    ->options([
                        StockMovement::TYPE_IN => 'Приход',
                        StockMovement::TYPE_OUT => 'Расход',
                    ]),
            ])
            ->emptyStateHeading('Движений пока нет');
    }
}
