<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaterialStocks\Tables;

use App\Models\MaterialStock;
use App\Models\StockMovement;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class MaterialStocksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Материал')
                    ->description(fn (MaterialStock $record): ?string => $record->sku)
                    ->searchable(['name', 'sku'])
                    ->weight('semibold')
                    ->wrap(),

                TextColumn::make('quantity')
                    ->label('Остаток')
                    ->state(fn (MaterialStock $record): string => rtrim(rtrim(number_format((float) $record->quantity, 3, '.', ' '), '0'), '.')
                        .' '.$record->unit->getLabel())
                    ->badge()
                    ->color(fn (MaterialStock $record): string => $record->isBelowLimit() ? 'danger' : 'success')
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('min_limit')
                    ->label('Минимум')
                    ->formatStateUsing(fn ($state): string => rtrim(rtrim((string) $state, '0'), '.'))
                    ->color('gray')
                    ->alignEnd(),

                TextColumn::make('price_per_unit')
                    ->label('Цена закупа')
                    ->formatStateUsing(fn ($state): string => Money::format($state))
                    ->sortable()
                    ->alignEnd()
                    ->visible(fn (): bool => auth()->user()?->role->seesMoney() ?? false),

                TextColumn::make('stock_value')
                    ->label('Стоимость остатка')
                    ->state(fn (MaterialStock $record): float => $record->stockValue())
                    ->formatStateUsing(fn ($state): string => Money::format($state))
                    ->alignEnd()
                    ->visible(fn (): bool => auth()->user()?->role->seesMoney() ?? false),

                TextColumn::make('supplier')->label('Поставщик')->toggleable()->placeholder('—'),
            ])
            ->filters([
                Filter::make('below_limit')
                    ->label('Ниже минимума')
                    ->query(fn ($query) => $query->belowLimit()),
            ])
            ->recordActions([
                Action::make('receipt')
                    ->label('Приход')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->modalHeading(fn (MaterialStock $record): string => "Приход: {$record->name}")
                    ->modalSubmitActionLabel('Оприходовать')
                    ->schema([
                        TextInput::make('quantity')
                            ->label('Количество')
                            ->numeric()
                            ->minValue(0.001)
                            ->required()
                            ->suffix(fn (MaterialStock $record): string => $record->unit->getLabel()),

                        TextInput::make('price_per_unit')
                            ->label('Цена закупа за единицу')
                            ->numeric()
                            ->minValue(0)
                            ->default(fn (MaterialStock $record): string => (string) $record->price_per_unit)
                            ->suffix(config('gravit.currency.symbol'))
                            ->visible(fn (): bool => auth()->user()?->role->seesMoney() ?? false),

                        TextInput::make('comment')->label('Комментарий')->placeholder('Накладная №…'),
                    ])
                    ->action(function (MaterialStock $record, array $data): void {
                        $quantity = round((float) $data['quantity'], 3);
                        $price = isset($data['price_per_unit'])
                            ? round((float) $data['price_per_unit'], 2)
                            : (float) $record->price_per_unit;

                        DB::transaction(function () use ($record, $quantity, $price, $data): void {
                            StockMovement::create([
                                'material_stock_id' => $record->id,
                                'user_id' => auth()->id(),
                                'type' => StockMovement::TYPE_IN,
                                'quantity' => $quantity,
                                'price_per_unit' => $price,
                                'comment' => $data['comment'] ?? null,
                            ]);

                            $record->increment('quantity', $quantity);

                            // Цена закупа — всегда последняя: по ней считается
                            // себестоимость новых заказов.
                            if ($price > 0.0) {
                                $record->forceFill(['price_per_unit' => $price])->save();
                            }
                        });

                        Notification::make()
                            ->success()
                            ->title('Оприходовано')
                            ->body("{$record->name}: +{$quantity} {$record->unit->getLabel()}")
                            ->send();
                    }),

                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
