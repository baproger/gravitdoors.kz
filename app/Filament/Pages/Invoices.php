<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Enums\PipelineType;
use App\Filament\Actions\DealActions;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\User;
use App\Services\AccessControl;
use App\Support\Filament\TableFilters;
use App\Support\Money;
use App\Support\Plural;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Счета: сколько по каждой сделке выставлено, оплачено и осталось.
 *
 * Витрина над сделками продаж — отдельных документов-счетов нет (см. README, раздел
 * «Что не сделано и планы»,
 * шаг 5): дебиторка считается из total_price − prepayment, просрочка — по сроку сдачи.
 */
class Invoices extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Финансы';

    protected static ?string $navigationLabel = 'Счета';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'invoices';

    protected string $view = 'filament.pages.invoices';

    #[Url(except: 'awaiting')]
    public string $mode = 'awaiting';

    public static function canAccess(): bool
    {
        return AccessControl::can(Permission::FinanceInvoices);
    }

    public function getTitle(): string
    {
        return 'Счета и дебиторка';
    }

    public function getSubheading(): ?string
    {
        return 'Выставлено — сумма договора, оплачено — платежи с чеками, остаток — то, что клиент ещё должен.';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = self::overdueQuery()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public function updatedMode(): void
    {
        $this->resetTable();
    }

    /** @return array<string, array{label: string, count: int, sum: float}> */
    public function modes(): array
    {
        $sum = fn (Builder $q): float => round((float) $q->selectRaw('SUM(total_price - prepayment) as due')->value('due'), 2);

        return [
            'awaiting' => ['label' => 'Ожидают оплату', 'count' => self::awaitingQuery()->count(), 'sum' => $sum(self::awaitingQuery())],
            'overdue' => ['label' => 'Просрочены', 'count' => self::overdueQuery()->count(), 'sum' => $sum(self::overdueQuery())],
            'paid' => ['label' => 'Оплачены', 'count' => self::paidQuery()->count(), 'sum' => round((float) self::paidQuery()->sum('total_price'), 2)],
            'all' => ['label' => 'Все', 'count' => self::baseQuery()->count(), 'sum' => round((float) self::baseQuery()->sum('total_price'), 2)],
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => (match ($this->mode) {
                'overdue' => self::overdueQuery(),
                'paid' => self::paidQuery(),
                'all' => self::baseQuery(),
                default => self::awaitingQuery(),
            })->with(['currentStage', 'manager']))
            ->defaultSort(fn (Builder $query) => $this->mode === 'overdue' ? $query->orderBy('due_date') : $query->orderByDesc('id'))
            ->columns([
                TextColumn::make('number')
                    ->label('Сделка')
                    ->url(fn (Deal $record): string => DealResource::cardUrl($record))
                    ->description(fn (Deal $record): string => $record->clientTitle())
                    ->searchable(['number', 'title', 'client_name', 'client_company', 'client_phone'])
                    ->weight('semibold'),
                TextColumn::make('total_price')->label('Выставлено')->state(fn (Deal $r): string => Money::format($r->total_price))->alignEnd()->sortable(),
                TextColumn::make('prepayment')->label('Оплачено')->state(fn (Deal $r): string => Money::format($r->prepayment))->color('success')->alignEnd()->sortable(),
                TextColumn::make('remaining')
                    ->label('Остаток')
                    ->state(fn (Deal $r): string => Money::format($r->remainingPayment()))
                    ->color(fn (Deal $r): string => $r->remainingPayment() > 0 ? 'danger' : 'gray')
                    ->weight('semibold')
                    ->alignEnd(),
                TextColumn::make('due_date')
                    ->label('Срок сдачи')
                    ->date('d.m.Y')
                    ->description(fn (Deal $r): ?string => $r->isOverdue() ? 'просрочено '.$r->overdueDays().' '.Plural::choose($r->overdueDays(), 'день', 'дня', 'дней') : null)
                    ->color(fn (Deal $r): ?string => $r->isOverdue() ? 'danger' : null)
                    ->sortable(),
                TextColumn::make('currentStage.name')->label('Этап')->badge()->color('gray')->toggleable(),
                TextColumn::make('manager.name')->label('Менеджер')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('manager_id')->label('Менеджер')->options(fn (): array => User::query()->whereHas('deals')->orderBy('name')->pluck('name', 'id')->all()),

                SelectFilter::make('current_stage_id')
                    ->label('Этап')
                    ->options(fn (): array => FactoryStage::query()->ofPipeline(PipelineType::Sales)->ordered()->pluck('name', 'id')->all()),

                TableFilters::period('due_date', 'Срок сдачи'),
            ])
            ->filtersFormColumns(2)
            ->recordActions([
                // Те же кнопки, что в карточке сделки и списке (DealActions).
                DealActions::pay(),
                DealActions::remind(),
            ])
            ->paginated([25, 50])
            ->emptyStateHeading(fn (): string => match ($this->mode) {
                'overdue' => 'Просроченных счетов нет',
                'paid' => 'Полностью оплаченных сделок пока нет',
                default => 'Все счета оплачены',
            });
    }

    /** @return Builder<Deal> */
    private static function baseQuery(): Builder
    {
        return Deal::query()->sales()->where('total_price', '>', 0);
    }

    /** @return Builder<Deal> */
    private static function awaitingQuery(): Builder
    {
        return self::baseQuery()->open()->whereColumn('total_price', '>', 'prepayment');
    }

    /** @return Builder<Deal> */
    private static function overdueQuery(): Builder
    {
        return self::awaitingQuery()->whereDate('due_date', '<', today());
    }

    /** @return Builder<Deal> */
    private static function paidQuery(): Builder
    {
        return self::baseQuery()->whereColumn('prepayment', '>=', 'total_price');
    }
}
