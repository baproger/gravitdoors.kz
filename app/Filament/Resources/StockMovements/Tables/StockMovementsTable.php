<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockMovements\Tables;

use App\Enums\Permission;
use App\Models\MaterialStock;
use App\Models\StockMovement;
use App\Services\AccessControl;
use App\Support\Filament\TableFilters;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                    ->visible(fn (): bool => AccessControl::can(Permission::KanbanMoney)),

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

                SelectFilter::make('material_stock_id')
                    ->label('Материал')
                    ->options(fn (): array => MaterialStock::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),

                Filter::make('by_order')
                    ->label('Только по нарядам')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereNotNull('deal_id')),

                TableFilters::period('created_at', 'Дата движения'),
            ])
            ->filtersFormColumns(2)
            ->emptyStateHeading('Движений пока нет');
    }
}
