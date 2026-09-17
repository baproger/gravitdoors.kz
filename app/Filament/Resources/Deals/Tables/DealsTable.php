<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Tables;

use App\Enums\DealSource;
use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Exceptions\ProductionException;
use App\Filament\Resources\Deals\DealResource;
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
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class DealsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            // Этап, наряд и менеджер — одним запросом на страницу, а не по запросу на строку:
            // пометка «ждёт завод» и имя ответственного есть в каждой строке.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['currentStage', 'manager', 'productionOrder.currentStage', 'stageVisits']))
            ->recordClasses(fn (Deal $record): ?string => $record->isOverdue() || $record->isMeasurementOverdue() ? 'dl-row--overdue' : null)
            ->columns([
                // Строка из блоков вместо десяти колонок: название больше не сжимается
                // до 90 px с переносом на пять строк, а на телефоне блоки встают
                // друг под другом без горизонтальной прокрутки.
                Split::make([
                    Stack::make([
                        TextColumn::make('title')
                            ->label('Сделка')
                            ->weight(FontWeight::SemiBold)
                            ->searchable(['title', 'number', 'client_name', 'client_company', 'client_phone', 'client_bin']),

                        TextColumn::make('client_line')
                            ->label('Клиент')
                            ->state(fn (Deal $record): string => collect([
                                $record->number,
                                $record->clientTitle(),
                                $record->city,
                            ])->filter()->implode(' · '))
                            ->color('gray')
                            ->size(TextSize::ExtraSmall),
                    ])
                        ->space(1)
                        ->grow()
                        ->extraAttributes(['class' => 'dl-col dl-col--main']),

                    Stack::make([
                        TextColumn::make('client_phone')
                            ->label('Телефон')
                            ->icon('heroicon-m-phone')
                            ->iconColor('gray')
                            ->size(TextSize::Small)
                            ->url(fn (Deal $record): ?string => $record->client_phone
                                ? 'tel:'.preg_replace('/\D+/', '', $record->client_phone)
                                : null)
                            ->placeholder('без телефона'),

                        TextColumn::make('pipeline_type')
                            ->label('Воронка')
                            ->badge()
                            // Во вкладках продаж и завода воронка и так понятна.
                            ->visible(fn ($livewire): bool => ($livewire->activeTab ?? null) === 'all'),
                    ])
                        ->space(1)
                        ->grow(false)
                        ->extraAttributes(['class' => 'dl-col dl-col--phone']),

                    Stack::make([
                        TextColumn::make('currentStage.name')
                            ->label('Этап')
                            ->badge()
                            ->color(fn (Deal $record): string => $record->currentStage?->color ?? 'gray')
                            ->placeholder('без этапа'),

                        TextColumn::make('stage_note')
                            ->label('На этапе')
                            ->state(fn (Deal $record): ?string => self::stageNote($record))
                            ->color(fn (Deal $record): string => match (true) {
                                $record->status_id === DealStatus::Cancelled, $record->isMeasurementOverdue() => 'danger',
                                $record->activeProductionOrder() !== null => 'info',
                                default => 'gray',
                            })
                            ->size(TextSize::ExtraSmall),
                    ])
                        ->space(1)
                        ->grow(false)
                        ->extraAttributes(['class' => 'dl-col dl-col--stage']),

                    Stack::make([
                        TextColumn::make('total_price')
                            ->label('Сумма')
                            ->formatStateUsing(fn ($state): string => Money::format((float) $state))
                            ->weight(FontWeight::SemiBold)
                            ->sortable(),

                        TextColumn::make('remaining')
                            ->label('Остаток')
                            ->state(fn (Deal $record): ?string => match (true) {
                                (float) $record->total_price <= 0 => null,
                                $record->isPaidInFull() => 'оплачено',
                                default => 'остаток '.Money::format($record->remainingPayment()),
                            })
                            ->color(fn (Deal $record): string => $record->isPaidInFull() ? 'success' : 'warning')
                            ->size(TextSize::ExtraSmall),
                    ])
                        ->space(1)
                        ->alignment(Alignment::End)
                        ->grow(false)
                        ->visible(fn (): bool => auth()->user()?->role->seesMoney() ?? false)
                        ->extraAttributes(['class' => 'dl-col dl-col--money']),

                    Stack::make([
                        TextColumn::make('due_date')
                            ->label('Срок')
                            ->state(fn (Deal $record): ?string => self::dueLabel($record))
                            ->icon('heroicon-m-calendar')
                            ->iconColor(fn (Deal $record): string => $record->isOverdue() ? 'danger' : 'gray')
                            ->color(fn (Deal $record): ?string => $record->isOverdue() ? 'danger' : null)
                            ->size(TextSize::Small)
                            ->sortable()
                            ->placeholder('без срока'),

                        TextColumn::make('manager.name')
                            ->label('Ответственный')
                            ->icon('heroicon-m-user')
                            ->iconColor('gray')
                            ->color('gray')
                            ->size(TextSize::ExtraSmall)
                            ->placeholder('без ответственного'),
                    ])
                        ->space(1)
                        ->grow(false)
                        ->extraAttributes(['class' => 'dl-col dl-col--due']),
                ])->from('md'),
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

                SelectFilter::make('manager_id')
                    ->label('Менеджер')
                    ->relationship('manager', 'name')
                    ->searchable(),

                SelectFilter::make('source')
                    ->label('Источник')
                    ->options(DealSource::class),
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
                        ->authorize(fn (Deal $record): bool => auth()->user()?->can('move', $record) ?? false)
                        ->visible(fn (Deal $record): bool => ! $record->isFactoryOrder()
                            && ! $record->status_id->isClosed()
                            && $record->activeProductionOrder() === null
                            && $record->completedProductionOrder() === null)
                        ->action(function (Deal $record, DoorProductionService $production): void {
                            // Через moveToStage, а не напрямую: иначе кнопка обходила бы
                            // регламент этапов, который проверяется при переходе.
                            $stage = FactoryStage::query()
                                ->ofPipeline(PipelineType::Sales)
                                ->active()
                                ->where('triggers_production', true)
                                ->ordered()
                                ->first();

                            if (! $stage) {
                                Notification::make()->danger()
                                    ->title('Этап передачи в производство не настроен')
                                    ->send();

                                return;
                            }

                            try {
                                $production->moveToStage($record, $stage, auth()->user());

                                $order = $record->refresh()->productionOrder;

                                Notification::make()
                                    ->success()
                                    ->title('Наряд создан')
                                    ->body("Производственный наряд {$order?->number} принят цехом.")
                                    ->send();
                            } catch (ProductionException $e) {
                                Notification::make()->danger()
                                    ->title('Не удалось передать в цех')
                                    ->body($e->getMessage())
                                    ->persistent()
                                    ->send();
                            }
                        }),

                    Action::make('completeStage')
                        ->label('Завершить этап цеха')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading(fn (Deal $record): string => 'Этап «'.($record->currentStage->name ?? '—').'» выполнен?')
                        ->modalDescription('Этап закроется, оплата запишется на исполнителя. Последний этап завершит наряд и вернёт сделку отделу продаж.')
                        // Видимость — не защита: право проверяется и на сервере, иначе рабочий
                        // закрывал бы этапы из списка, хотя политика ему это запрещает.
                        ->authorize(fn (Deal $record): bool => auth()->user()?->can('move', $record) ?? false)
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
                        ->authorize(fn (Deal $record): bool => auth()->user()?->can('update', $record) ?? false)
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
                        ->authorize(fn (Deal $record): bool => auth()->user()?->can('update', $record) ?? false)
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
                    DeleteBulkAction::make()
                        // Сделки с нарядом в цеху и живые наряды из выборки выпадают:
                        // сначала отмена наряда, потом удаление.
                        ->action(function (Collection $records): void {
                            /** @var Collection<int, Deal> $records */
                            [$deletable, $kept] = $records->partition(fn (Deal $deal): bool => $deal->canBeDeleted());

                            $deletable->each(fn (Deal $deal): ?bool => $deal->delete());

                            if ($kept->isNotEmpty()) {
                                Notification::make()
                                    ->warning()
                                    ->title('Не удалено: '.$kept->count())
                                    ->body('У сделки наряд в цеху или наряд ещё открыт — сначала отмените наряд: '
                                        .$kept->pluck('number')->take(5)->implode(', '))
                                    ->persistent()
                                    ->send();
                            }

                            if ($deletable->isNotEmpty()) {
                                Notification::make()->success()->title('Удалено: '.$deletable->count())->send();
                            }
                        }),
                ]),
            ])
            ->emptyStateHeading('Сделок пока нет')
            ->emptyStateDescription(fn (): string => DealResource::canCreate()
                ? 'Нажмите «Новая сделка», чтобы завести первого клиента.'
                : 'Наряды появятся, когда отдел продаж передаст заказ на завод.');
    }

    /** «просрочено на 3 дн.», «сдача сегодня», «через 5 дн.», «до 04.10.2026». */
    private static function dueLabel(Deal $record): ?string
    {
        if ($record->due_date === null) {
            return null;
        }

        if ($record->status_id->isClosed()) {
            return 'до '.$record->due_date->format('d.m.Y');
        }

        $days = (int) today()->diffInDays($record->due_date, false);

        return match (true) {
            $days < 0 => 'просрочено на '.abs($days).' дн.',
            $days === 0 => 'сдача сегодня',
            $days <= 7 => 'через '.$days.' дн. · '.$record->due_date->format('d.m'),
            default => 'до '.$record->due_date->format('d.m.Y'),
        };
    }

    /** Что происходит со сделкой прямо сейчас — одна короткая строка под этапом. */
    private static function stageNote(Deal $record): ?string
    {
        if ($record->status_id->isClosed()) {
            return $record->status_id->getLabel();
        }

        if ($record->isMeasurementOverdue()) {
            return 'замер просрочен на '.$record->measurementOverdueDays().' дн.';
        }

        if ($order = $record->activeProductionOrder()) {
            return 'ждёт завод'.($order->currentStage ? ': '.$order->currentStage->name : '');
        }

        if ($record->stage_entered_at === null) {
            return null;
        }

        $hours = $record->hours_on_stage;

        return 'на этапе '.match (true) {
            $hours < 1 => 'меньше часа',
            $hours < 24 => (int) round($hours).' ч',
            default => (int) floor($hours / 24).' дн.',
        };
    }
}
