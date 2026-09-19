<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Tables;

use App\Enums\DealSource;
use App\Enums\DealStatus;
use App\Enums\Permission;
use App\Enums\PipelineType;
use App\Filament\Actions\DealActions;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Services\AccessControl;
use App\Support\Filament\TableFilters;
use App\Support\Money;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['currentStage', 'manager', 'productionOrder.currentStage', 'stageVisits', 'latestStageVisit.user']))
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

                        // Кто двинул сделку. Раньше это было видно только в
                        // истории внутри карточки, и директор не замечал, что
                        // менеджер перевёл заказ на другой этап.
                        TextColumn::make('stage_moved')
                            ->label('Этап изменён')
                            ->state(fn (Deal $record): ?string => self::stageMoved($record))
                            ->color('gray')
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
                        ->visible(fn (): bool => AccessControl::can(Permission::KanbanMoney))
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

                TableFilters::period('due_date', 'Срок сдачи'),
                TableFilters::period('created_at', 'Дата создания'),
                TableFilters::period('stage_entered_at', 'Этап изменён'),
            ])
            ->filtersFormColumns(2)
            ->recordActions([
                // Действия те же, что в карточке (DealActions): одни правила и права.
                ActionGroup::make([
                    EditAction::make(),
                    ...DealActions::forTable(),
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

    /** Кто и когда привёл сделку на текущий этап; у старых заходов автора нет. */
    private static function stageMoved(Deal $record): ?string
    {
        $who = $record->latestStageVisit?->user?->name;

        if ($who === null || $record->stage_entered_at === null) {
            return null;
        }

        return 'перенёс '.$who.', '.self::ago($record->stage_entered_at->diffInHours(now()));
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

        return 'на этапе '.self::ago($record->hours_on_stage);
    }

    /** Часы человеческим языком: «меньше часа», «5 ч», «3 дн.». */
    private static function ago(float $hours): string
    {
        return match (true) {
            $hours < 1 => 'меньше часа',
            $hours < 24 => (int) round($hours).' ч',
            default => (int) floor($hours / 24).' дн.',
        };
    }
}
