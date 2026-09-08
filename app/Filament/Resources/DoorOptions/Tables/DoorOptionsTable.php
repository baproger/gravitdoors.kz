<?php

declare(strict_types=1);

namespace App\Filament\Resources\DoorOptions\Tables;

use App\Enums\DoorOptionCategory;
use App\Models\DoorOption;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

class DoorOptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->defaultGroup(
                Group::make('category')
                    ->label('Группа')
                    ->getTitleFromRecordUsing(fn (DoorOption $record): string => $record->category->getLabel()),
            )
            ->columns([
                TextColumn::make('label')->label('Опция')->weight('semibold')->searchable(),

                TextColumn::make('price')
                    ->label('Цена')
                    ->money('KZT', locale: 'ru')
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('price_type')->label('Тип цены')->badge()->color('gray'),

                TextColumn::make('materialStock.name')
                    ->label('Списывается со склада')
                    ->description(fn (DoorOption $record): ?string => (float) $record->consumption > 0
                        ? 'расход '.rtrim(rtrim((string) $record->consumption, '0'), '.').' '.$record->materialStock?->unit->getLabel()
                        : null)
                    ->placeholder('—')
                    ->toggleable(),

                IconColumn::make('is_default')->label('По умолч.')->boolean(),

                ToggleColumn::make('is_active')->label('Активна'),
            ])
            ->filters([
                SelectFilter::make('category')->label('Группа')->options(DoorOptionCategory::class),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
