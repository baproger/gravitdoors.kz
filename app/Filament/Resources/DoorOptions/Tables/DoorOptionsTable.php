<?php

declare(strict_types=1);

namespace App\Filament\Resources\DoorOptions\Tables;

use App\Enums\DoorOptionCategory;
use App\Models\DoorOption;
use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

/**
 * Прайс конфигуратора.
 *
 * Плотная таблица вместо просторной: позиций под сотню, и менеджеру нужно
 * видеть максимум строк сразу. Цена и тип расчёта собраны в одну колонку —
 * порознь они занимали половину ширины ради двух коротких значений.
 */
class DoorOptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->defaultPaginationPageOption(50)
            ->defaultGroup(
                Group::make('category')
                    ->label('Группа')
                    ->getTitleFromRecordUsing(fn (DoorOption $record): string => $record->category->getLabel()),
            )
            ->columns([
                TextColumn::make('label')
                    ->label('Опция')
                    ->description(fn (DoorOption $record): string => $record->code)
                    ->weight('semibold')
                    ->searchable(['label', 'code'])
                    ->wrap(),

                TextColumn::make('price')
                    ->label('Цена')
                    ->state(fn (DoorOption $record): string => Money::format((float) $record->price))
                    ->description(fn (DoorOption $record): string => $record->price_type->getLabel())
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('materialStock.name')
                    ->label('Со склада')
                    ->description(fn (DoorOption $record): ?string => (float) $record->consumption > 0
                        ? rtrim(rtrim((string) $record->consumption, '0'), '.').' '.$record->materialStock?->unit->getLabel()
                        : null)
                    ->placeholder('—')
                    ->wrap()
                    ->visibleFrom('md'),

                IconColumn::make('is_default')
                    ->label('По умолч.')
                    ->boolean()
                    ->trueIcon('heroicon-m-check-circle')
                    ->falseIcon('heroicon-o-minus-small')
                    ->falseColor('gray')
                    ->visibleFrom('lg'),

                ToggleColumn::make('is_active')->label('Активна'),
            ])
            ->filters([
                SelectFilter::make('category')->label('Группа')->options(DoorOptionCategory::class),

                TernaryFilter::make('material_stock_id')
                    ->label('Списывается со склада')
                    ->nullable()
                    ->placeholder('Все')
                    ->trueLabel('Со списанием')
                    ->falseLabel('Без списания')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('material_stock_id'),
                        false: fn ($query) => $query->whereNull('material_stock_id'),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->recordActions([EditAction::make()->iconButton(), DeleteAction::make()->iconButton()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->emptyStateHeading('Прайс пуст')
            ->emptyStateDescription('Добавьте позиции — из них конфигуратор считает цену двери.');
    }
}
