<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\DealSource;
use App\Enums\Permission;
use App\Enums\PipelineType;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Services\AccessControl;
use App\Support\Cities;
use App\Support\Filament\TableFilters;
use App\Support\Money;
use App\Support\Plural;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Просроченные сделки и наряды.
 *
 * Две вкладки: прошёл срок сдачи и застряли на этапе дольше норматива.
 * Самая большая просрочка — сверху: по ней и надо звонить первой.
 * Цех видит только свои наряды и без денег.
 */
class OverdueDeals extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-fire';

    protected static string|UnitEnum|null $navigationGroup = 'Работа';

    protected static ?string $navigationLabel = 'Просроченные';

    protected static ?int $navigationSort = 25;

    protected string $view = 'filament.pages.overdue-deals';

    #[Url(except: 'due')]
    public string $mode = 'due';

    public static function canAccess(): bool
    {
        return AccessControl::can(Permission::WorkOverdue);
    }

    public function getTitle(): string
    {
        return 'Просроченные';
    }

    public function getSubheading(): ?string
    {
        return 'Самая большая просрочка — сверху. Срок сдачи считается по дням, норматив этапа — по часам.';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::scopedQuery()->overdue()->count() + static::scopedQuery()->measurementOverdue()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    /** @return array<string, array{label: string, count: int}> */
    public function modes(): array
    {
        return [
            'due' => ['label' => 'Срок сдачи прошёл', 'count' => static::scopedQuery()->overdue()->count()],
            'measurement' => ['label' => 'Замер не проведён', 'count' => static::scopedQuery()->measurementOverdue()->count()],
            'stage' => ['label' => 'Застряли на этапе', 'count' => count($this->stuckIds())],
        ];
    }

    public function updatedMode(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $money = AccessControl::can(Permission::KanbanMoney);

        return $table
            ->query(fn (): Builder => match ($this->mode) {
                'stage' => static::scopedQuery()->whereIn('id', $this->stuckIds())->with(['currentStage', 'manager']),
                'measurement' => static::scopedQuery()->measurementOverdue()->with(['currentStage', 'manager']),
                default => static::scopedQuery()->overdue()->with(['currentStage', 'manager']),
            })
            ->defaultSort(fn (Builder $query) => match ($this->mode) {
                'stage' => $query->orderBy('stage_entered_at'),
                'measurement' => $query->orderBy('measured_at'),
                default => $query->orderBy('due_date'),
            })
            // Рабочему карточка не открывается — строка без ссылки, а не 403.
            ->recordUrl(fn (Deal $record): ?string => auth()->user()?->can('update', $record)
                ? DealResource::cardUrl($record)
                : null)
            ->recordClasses('od-row')
            ->paginated([25, 50])
            ->columns([
                TextColumn::make('overdue')
                    ->label('Просрочка')
                    ->state(fn (Deal $record): string => match ($this->mode) {
                        'stage' => self::hours($record->stageOverdueHours()).' сверх нормы',
                        'measurement' => $record->measurementOverdueDays().' '.Plural::choose($record->measurementOverdueDays(), 'день', 'дня', 'дней'),
                        default => $record->overdueDays().' '.Plural::choose($record->overdueDays(), 'день', 'дня', 'дней'),
                    })
                    ->badge()
                    ->color('danger')
                    ->weight(FontWeight::Bold),

                TextColumn::make('title')
                    ->label('Сделка')
                    ->description(fn (Deal $record): string => collect([$record->number, $record->clientTitle(), $record->city])->filter()->implode(' · '))
                    ->weight(FontWeight::SemiBold)
                    ->searchable(['title', 'number', 'client_name', 'client_company', 'client_phone'])
                    ->wrap(),

                TextColumn::make('client_phone')
                    ->label('Телефон')
                    ->icon('heroicon-m-phone')
                    ->url(fn (Deal $record): ?string => $record->client_phone ? 'tel:'.preg_replace('/\D+/', '', $record->client_phone) : null)
                    ->placeholder('—')
                    ->visibleFrom('md'),

                TextColumn::make('currentStage.name')
                    ->label('Этап')
                    ->badge()
                    ->color(fn (Deal $record): string => $record->currentStage?->color ?? 'gray')
                    ->description(fn (Deal $record): ?string => $this->mode === 'stage'
                        ? 'норматив '.self::hours((float) ($record->currentStage?->estimated_hours ?? 0)).', стоит '.self::hours($record->hours_on_stage)
                        : ($record->isStageOverdue() ? 'и на этапе дольше нормы' : null)),

                TextColumn::make('measured_at')
                    ->label('Замер')
                    ->dateTime('d.m.Y H:i')
                    ->color('danger')
                    ->weight(FontWeight::SemiBold)
                    ->visible(fn (): bool => $this->mode === 'measurement'),

                TextColumn::make('due_date')
                    ->label('Срок сдачи')
                    ->date('d.m.Y')
                    ->color(fn (Deal $record): ?string => $record->isOverdue() ? 'danger' : null)
                    ->weight(fn (Deal $record): ?FontWeight => $record->isOverdue() ? FontWeight::SemiBold : null)
                    ->sortable(),

                TextColumn::make('remaining')
                    ->label('Остаток к оплате')
                    ->state(fn (Deal $record): string => $record->isPaidInFull() ? 'оплачено' : Money::format($record->remainingPayment()))
                    ->color(fn (Deal $record): string => $record->isPaidInFull() ? 'success' : 'warning')
                    ->size(TextSize::Small)
                    ->visible($money)
                    ->visibleFrom('lg'),

                TextColumn::make('manager.name')
                    ->label('Ответственный')
                    ->placeholder('—')
                    ->visibleFrom('xl'),
            ])
            ->filters([
                SelectFilter::make('manager_id')
                    ->label('Ответственный')
                    ->relationship('manager', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('current_stage_id')
                    ->label('Этап')
                    ->options(fn (): array => FactoryStage::query()->active()->ordered()->pluck('name', 'id')->all())
                    ->multiple(),

                SelectFilter::make('city')
                    ->label('Город')
                    ->options(fn (): array => Cities::options())
                    ->searchable(),

                SelectFilter::make('pipeline_type')
                    ->label('Воронка')
                    ->options(PipelineType::class)
                    // На вкладке «по сроку» наряды и так не показываются, а цех
                    // видит только их — выбор был бы из одного варианта.
                    ->visible(fn (): bool => $this->mode === 'stage' && AccessControl::can(Permission::WorkSalesKanban)),

                SelectFilter::make('source')
                    ->label('Источник')
                    ->options(DealSource::class),

                Filter::make('unpaid')
                    ->label('Только с долгом')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereColumn('prepayment', '<', 'total_price'))
                    ->visible($money),

                TableFilters::period('due_date', 'Срок сдачи'),
            ])
            ->filtersFormColumns(2)
            ->emptyStateIcon('heroicon-o-check-badge')
            ->emptyStateHeading('Просроченных нет')
            ->emptyStateDescription(match ($this->mode) {
                'stage' => 'Все сделки укладываются в нормативы этапов.',
                'measurement' => 'Все назначенные замеры проведены вовремя.',
                default => 'Все открытые сделки в срок.',
            });
    }

    /**
     * Цех видит только наряды — как и в остальной панели. Продажам по сроку сдачи
     * показываются сделки, а не наряды: наряд копирует срок сделки, и без этого
     * один заказ считался бы просроченным дважды. Застрявшие на этапе — и те, и другие.
     */
    private static function scopedQuery(bool $includeOrders = false): Builder
    {
        $query = Deal::query()->visibleTo(auth()->user());

        // По сроку сдачи показываем сделки, а не их наряды: срок копируется,
        // и один заказ считался бы просроченным дважды.
        return $includeOrders || ! AccessControl::can(Permission::WorkSalesKanban)
            ? $query
            : $query->sales();
    }

    /**
     * Сделки на этапе дольше норматива. Норматив лежит в этапе, разница дат
     * считается по-разному в SQLite и MySQL — считаем в PHP по открытым сделкам.
     *
     * @return list<int>
     */
    private function stuckIds(): array
    {
        return static::scopedQuery(includeOrders: true)
            ->open()
            ->whereNotNull('stage_entered_at')
            ->whereHas('currentStage', fn (Builder $q) => $q->where('estimated_hours', '>', 0))
            ->with(['currentStage', 'stageVisits'])
            ->get()
            ->filter(fn (Deal $deal): bool => $deal->isStageOverdue())
            ->sortByDesc(fn (Deal $deal): float => $deal->stageOverdueHours())
            ->pluck('id')
            ->all();
    }

    private static function hours(float $hours): string
    {
        return $hours >= 24
            ? floor($hours / 24).' дн. '.round(fmod($hours, 24)).' ч'
            : round($hours, 1).' ч';
    }
}
