<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\RelationManagers;

use App\Models\Deal;
use App\Models\ProductionLog;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Тайминг цеха: сколько реально заняли этапы против норматива и сколько начислено сдельно. */
class ProductionLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'productionLogs';

    // Блок грузится сразу: ленивая подгрузка оставляла на странице
    // бесконечное «Loading…», а история нужна вместе с карточкой.
    protected static bool $isLazy = false;

    protected static ?string $title = 'Тайминг производства';

    /**
     * Логи этапов принадлежат наряду, а не сделке продаж, поэтому у сделки
     * этот блок всегда был бы пустым — и выглядел как потерянные данные.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Deal && $ownerRecord->isFactoryOrder();
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('stage.name')->label('Этап')->weight('semibold'),

                TextColumn::make('worker.name')->label('Исполнитель')->placeholder('—'),

                TextColumn::make('started_at')->label('Начат')->dateTime('d.m.Y H:i')->placeholder('—'),

                TextColumn::make('finished_at')->label('Завершён')->dateTime('d.m.Y H:i')->placeholder('в работе'),

                TextColumn::make('duration')
                    ->label('Факт / норматив')
                    ->state(function (ProductionLog $record): string {
                        $actual = $record->durationHours();

                        return $actual === null
                            ? '— / '.$record->stage->estimated_hours.' ч'
                            : $actual.' / '.rtrim(rtrim((string) $record->stage->estimated_hours, '0'), '.').' ч';
                    })
                    ->badge()
                    ->color(fn (ProductionLog $record): string => match (true) {
                        $record->deviationHours() === null => 'gray',
                        $record->deviationHours() > 0 => 'danger',
                        default => 'success',
                    }),

                TextColumn::make('status')->label('Статус')->badge(),

                TextColumn::make('payout')
                    ->label('Начислено')
                    ->formatStateUsing(fn ($state): string => Money::format($state))
                    ->alignEnd()
                    ->summarize(Sum::make()->label('Итого')),
            ])
            ->emptyStateHeading('Наряд ещё не запускался в цех');
    }
}
