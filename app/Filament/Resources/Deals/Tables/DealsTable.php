<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Tables;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Exceptions\ProductionException;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Services\DoorProductionService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DealsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label('№')
                    ->searchable()
                    ->copyable()
                    ->weight('semibold')
                    ->size('sm'),

                TextColumn::make('title')
                    ->label('Сделка')
                    ->description(fn (Deal $record): string => $record->client_name)
                    ->searchable(['title', 'client_name', 'client_phone'])
                    ->wrap(),

                TextColumn::make('pipeline_type')
                    ->label('Воронка')
                    ->badge(),

                TextColumn::make('currentStage.name')
                    ->label('Этап')
                    ->badge()
                    ->color(fn (Deal $record): string => $record->currentStage?->color ?? 'gray')
                    ->description(fn (Deal $record): ?string => $record->currentStage
                        ? "⏱ {$record->hours_on_stage} ч"
                        : null),

                TextColumn::make('status_id')
                    ->label('Статус')
                    ->badge(),

                TextColumn::make('total_price')
                    ->label('Сумма')
                    ->formatStateUsing(fn ($state): string => Money::format($state))
                    ->sortable()
                    ->alignEnd()
                    ->visible(fn (): bool => auth()->user()?->role->seesMoney() ?? false),

                TextColumn::make('margin')
                    ->label('Маржа')
                    ->state(fn (Deal $record): string => $record->total_price > 0 ? $record->margin.' %' : '—')
                    ->color(fn (Deal $record): string => match (true) {
                        $record->margin >= 25 => 'success',
                        $record->margin >= 10 => 'warning',
                        default => 'danger',
                    })
                    ->badge()
                    ->toggleable()
                    ->visible(fn (): bool => auth()->user()?->role->seesMoney() ?? false),

                TextColumn::make('manager.name')
                    ->label('Менеджер')
                    ->toggleable()
                    ->placeholder('—'),

                TextColumn::make('due_date')
                    ->label('Срок')
                    ->date('d.m.Y')
                    ->sortable()
                    ->color(fn (Deal $record): string => $record->due_date?->isPast() ? 'danger' : 'gray')
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('pipeline_type')
                    ->label('Воронка')
                    ->options(PipelineType::class),

                SelectFilter::make('status_id')
                    ->label('Статус')
                    ->options(DealStatus::class)
                    ->multiple(),

                SelectFilter::make('current_stage_id')
                    ->label('Этап')
                    ->options(fn (): array => FactoryStage::query()->active()->ordered()->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),

                    Action::make('handOff')
                        ->label('Передать в производство')
                        ->icon('heroicon-o-arrow-right-circle')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Передать сделку на завод?')
                        ->modalDescription('Будет создан производственный наряд, а материалы спецификации спишутся со склада.')
                        ->visible(fn (Deal $record): bool => ! $record->isFactoryOrder()
                            && ! $record->status_id->isClosed()
                            && ! $record->productionOrder()->exists())
                        ->action(function (Deal $record, DoorProductionService $production): void {
                            try {
                                $order = $production->handOffToProduction($record, auth()->user());

                                Notification::make()
                                    ->success()
                                    ->title('Наряд создан')
                                    ->body("Производственный наряд {$order->number} принят цехом.")
                                    ->send();
                            } catch (ProductionException $e) {
                                Notification::make()->danger()->title('Не удалось передать в цех')->body($e->getMessage())->send();
                            }
                        }),

                    Action::make('completeStage')
                        ->label('Завершить этап цеха')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (Deal $record): bool => $record->isFactoryOrder() && ! $record->status_id->isClosed())
                        ->action(function (Deal $record, DoorProductionService $production): void {
                            try {
                                $stage = $record->currentStage?->name;
                                $production->completeCurrentStage($record, auth()->user());

                                Notification::make()
                                    ->success()
                                    ->title("Этап «{$stage}» закрыт")
                                    ->body($record->refresh()->currentStage?->name
                                        ? "Наряд перешёл на «{$record->currentStage->name}»."
                                        : 'Производство завершено, сделка готова к отгрузке.')
                                    ->send();
                            } catch (ProductionException $e) {
                                Notification::make()->danger()->title('Ошибка')->body($e->getMessage())->send();
                            }
                        }),

                    Action::make('cancelOrder')
                        ->label('Отменить наряд')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Отменить производственный наряд?')
                        ->modalDescription('Списанные материалы вернутся на склад, сделка клиента вернётся в работу.')
                        ->schema([
                            Textarea::make('reason')
                                ->label('Причина отмены')
                                ->required()
                                ->rows(2),
                        ])
                        ->visible(fn (Deal $record): bool => $record->isFactoryOrder() && ! $record->status_id->isClosed())
                        ->action(function (Deal $record, array $data, DoorProductionService $production): void {
                            try {
                                $production->cancelProduction($record, auth()->user(), $data['reason']);

                                Notification::make()
                                    ->success()
                                    ->title("Наряд {$record->number} отменён")
                                    ->body('Материалы возвращены на склад.')
                                    ->send();
                            } catch (ProductionException $e) {
                                Notification::make()->danger()->title('Ошибка')->body($e->getMessage())->send();
                            }
                        }),

                    Action::make('recalculate')
                        ->label('Пересчитать цену')
                        ->icon('heroicon-o-calculator')
                        ->visible(fn (Deal $record): bool => $record->salesDeal()->doorConfigurations()->exists())
                        ->action(function (Deal $record, DoorProductionService $production): void {
                            $production->syncPricing($record);

                            Notification::make()->success()->title('Цена пересчитана по спецификации')->send();
                        }),

                    Action::make('track')
                        ->label('Страница для клиента')
                        ->icon('heroicon-o-qr-code')
                        ->color('gray')
                        ->url(fn (Deal $record): string => route('track.show', $record->qr_code_hash))
                        ->openUrlInNewTab(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Сделок пока нет');
    }
}
