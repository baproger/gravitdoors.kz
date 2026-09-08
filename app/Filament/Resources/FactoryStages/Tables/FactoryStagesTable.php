<?php

declare(strict_types=1);

namespace App\Filament\Resources\FactoryStages\Tables;

use App\Enums\PipelineType;
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

class FactoryStagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('order')
            ->reorderable('order')
            ->defaultGroup(
                Group::make('pipeline_type')
                    ->label('Воронка')
                    ->getTitleFromRecordUsing(fn ($record): string => $record->pipeline_type->getLabel()),
            )
            ->columns([
                TextColumn::make('order')
                    ->label('№')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Этап')
                    ->weight('semibold')
                    ->description(fn ($record): ?string => $record->description)
                    ->searchable(),

                TextColumn::make('code')
                    ->label('Код')
                    ->color('gray')
                    ->size('sm')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('estimated_hours')
                    ->label('Норматив')
                    ->formatStateUsing(fn ($state): string => (float) $state > 0 ? rtrim(rtrim((string) $state, '0'), '.').' ч' : '—')
                    ->alignEnd(),

                TextColumn::make('operation_cost')
                    ->label('Сдельно')
                    ->formatStateUsing(fn ($state): string => (float) $state > 0
                        ? number_format((float) $state, 0, ',', ' ').' '.config('gravit.currency.symbol')
                        : '—')
                    ->alignEnd(),

                IconColumn::make('triggers_production')
                    ->label('→ Завод')
                    ->tooltip('Вход на этап создаёт производственный наряд')
                    ->boolean()
                    ->trueIcon('heroicon-o-arrow-right-circle')
                    ->falseIcon('heroicon-o-minus-small')
                    ->trueColor('warning')
                    ->falseColor('gray'),

                IconColumn::make('completes_production')
                    ->label('Завод →')
                    ->tooltip('Закрытие этапа переводит сделку в «Готово к отгрузке»')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-minus-small')
                    ->trueColor('success')
                    ->falseColor('gray'),

                ToggleColumn::make('is_active')->label('Активен'),
            ])
            ->filters([
                SelectFilter::make('pipeline_type')
                    ->label('Воронка')
                    ->options(PipelineType::class),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Этапов пока нет')
            ->emptyStateDescription('Добавьте этапы — из них соберутся колонки канбана обеих воронок.');
    }
}
