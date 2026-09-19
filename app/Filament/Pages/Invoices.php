<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\AccessLevel;
use App\Enums\DealEventType;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\PipelineType;
use App\Filament\Resources\Deals\DealResource;
use App\Models\CashAccount;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\DealPayment;
use App\Models\FactoryStage;
use App\Models\User;
use App\Services\AccessControl;
use App\Support\Filament\TableFilters;
use App\Support\Money;
use App\Support\Plural;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Счета: сколько по каждой сделке выставлено, оплачено и осталось.
 *
 * Витрина над сделками продаж — отдельных документов-счетов нет (см. finance-plan.md,
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
                Action::make('pay')
                    ->label('Принять оплату')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->modalHeading(fn (Deal $record): string => "Оплата по {$record->number} — остаток ".Money::format($record->remainingPayment()))
                    ->authorize(fn (): bool => AccessControl::can(Permission::FinanceIncomes, AccessLevel::Full))
                    ->visible(fn (Deal $record): bool => $record->remainingPayment() > 0 && ! $record->status_id->isClosed())
                    ->schema([
                        TextInput::make('amount')->label('Сумма')->numeric()->minValue(1)->required()->default(fn (Deal $record): float => $record->remainingPayment())->suffix(config('gravit.currency.symbol')),
                        Select::make('method')->label('Способ')->options(PaymentMethod::class)->default(PaymentMethod::Kaspi->value)->required()->native(false),
                        Select::make('account_id')
                            ->label('Счёт')
                            ->options(fn (): array => CashAccount::query()->where('is_active', true)->orderBy('id')->pluck('name', 'id')->all())
                            ->visible(fn (): bool => CashAccount::query()->where('is_active', true)->count() > 2)
                            ->native(false),
                        DatePicker::make('paid_at')->label('Дата')->default(now())->maxDate(now())->displayFormat('d.m.Y')->required(),
                        FileUpload::make('receipt_path')
                            ->label('Чек')
                            ->required()
                            ->directory('receipts')
                            ->disk('public')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                            ->maxSize(10240)
                            ->columnSpanFull(),
                    ])
                    ->action(function (Deal $record, array $data): void {
                        try {
                            DealPayment::create([
                                'deal_id' => $record->id,
                                'amount' => (float) $data['amount'],
                                'method' => $data['method'],
                                'account_id' => $data['account_id'] ?? null,
                                'paid_at' => $data['paid_at'],
                                'receipt_path' => $data['receipt_path'],
                            ]);

                            Notification::make()->success()->title('Оплата принята')
                                ->body('Остаток: '.Money::format($record->refresh()->remainingPayment()))->send();
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title('Не принято')->body(collect($e->errors())->flatten()->implode(' '))->send();
                        }
                    }),

                Action::make('remind')
                    ->label('Напомнить')
                    ->icon('heroicon-o-bell-alert')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Отметить напоминание об оплате?')
                    ->modalDescription('В историю сделки ляжет запись «напомнили об оплате» с датой и вашим именем. Отправка сообщения клиенту — следующий шаг плана.')
                    ->authorize(fn (): bool => AccessControl::can(Permission::FinanceInvoices, AccessLevel::Full))
                    ->visible(fn (Deal $record): bool => $record->remainingPayment() > 0 && ! $record->status_id->isClosed())
                    ->action(function (Deal $record): void {
                        DealEvent::record($record, DealEventType::PaymentReminder,
                            'Напомнили клиенту об оплате: остаток '.Money::format($record->remainingPayment()), auth()->user());

                        Notification::make()->success()->title('Напоминание записано в историю')->send();
                    }),
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
