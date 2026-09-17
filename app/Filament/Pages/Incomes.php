<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\PaymentMethod;
use App\Filament\Resources\Deals\DealResource;
use App\Models\CashAccount;
use App\Models\Deal;
use App\Models\DealPayment;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
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
 * Поступления денег: все платежи по сделкам одним списком.
 *
 * Отдельной таблицы нет — это витрина над deal_payments: платёж, принятый
 * здесь, тут же виден в карточке сделки, в её предоплате и в кассе.
 */
class Incomes extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = 'Финансы';

    protected static ?string $navigationLabel = 'Поступления';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'incomes';

    protected string $view = 'filament.pages.incomes';

    /** Месяц Y-m; пусто — за всё время. */
    #[Url(except: '')]
    public string $month = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->role->seesMoney() ?? false;
    }

    public function getTitle(): string
    {
        return 'Поступления денег';
    }

    public function getSubheading(): ?string
    {
        return 'Каждый платёж — с чеком и по конкретной сделке. Сумма платежей становится предоплатой сделки.';
    }

    public function updatedMonth(): void
    {
        $this->resetTable();
    }

    /** @return array<string, string> */
    public function monthOptions(): array
    {
        $options = ['' => 'За всё время'];

        foreach (range(0, 11) as $back) {
            $date = now()->subMonths($back);
            $options[$date->format('Y-m')] = mb_convert_case($date->translatedFormat('F Y'), MB_CASE_TITLE);
        }

        return $options;
    }

    /** @return array<string, float> */
    public function totals(): array
    {
        $byMethod = $this->periodQuery()
            ->selectRaw('method, SUM(amount) as total')
            ->groupBy('method')
            ->pluck('total', 'method')
            ->map(fn ($v): float => round((float) $v, 2));

        $cash = (float) ($byMethod[PaymentMethod::Cash->value] ?? 0);

        return [
            'total' => round((float) $byMethod->sum(), 2),
            'cash' => $cash,
            'bank' => round((float) $byMethod->sum() - $cash, 2),
            'count' => (float) $this->periodQuery()->count(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addPayment')
                ->label('Добавить поступление')
                ->icon('heroicon-o-plus')
                ->modalHeading('Новое поступление')
                ->authorize(fn (): bool => auth()->user()?->can('create', Deal::class) ?? false)
                ->schema([
                    Select::make('deal_id')
                        ->label('Сделка')
                        ->options(fn (): array => Deal::query()->sales()->open()->whereColumn('total_price', '>', 'prepayment')
                            ->orderByDesc('id')->limit(300)->get()
                            ->mapWithKeys(fn (Deal $d): array => [$d->id => "{$d->number} · {$d->clientTitle()} · остаток ".Money::format($d->remainingPayment())])
                            ->all())
                        ->searchable()
                        ->required()
                        ->native(false)
                        ->columnSpanFull(),
                    TextInput::make('amount')->label('Сумма')->numeric()->minValue(1)->required()->suffix(config('gravit.currency.symbol')),
                    Select::make('method')->label('Способ')->options(PaymentMethod::class)->default(PaymentMethod::Kaspi->value)->required()->native(false),
                    Select::make('account_id')
                        ->label('Счёт')
                        ->options(fn (): array => CashAccount::query()->where('is_active', true)->orderBy('id')->pluck('name', 'id')->all())
                        ->visible(fn (): bool => CashAccount::query()->where('is_active', true)->count() > 2)
                        ->native(false),
                    DatePicker::make('paid_at')->label('Дата')->default(now())->maxDate(now())->displayFormat('d.m.Y')->required(),
                    TextInput::make('comment')->label('Комментарий')->maxLength(255),
                    FileUpload::make('receipt_path')
                        ->label('Чек')
                        ->required()
                        ->directory('receipts')
                        ->disk('public')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                        ->maxSize(10240)
                        ->columnSpanFull(),
                ])
                ->action(function (array $data): void {
                    try {
                        $payment = DealPayment::create([
                            'deal_id' => (int) $data['deal_id'],
                            'amount' => (float) $data['amount'],
                            'method' => $data['method'],
                            'account_id' => $data['account_id'] ?? null,
                            'paid_at' => $data['paid_at'],
                            'receipt_path' => $data['receipt_path'],
                            'comment' => $data['comment'] ?? null,
                        ]);

                        Notification::make()->success()
                            ->title('Поступление принято')
                            ->body("Сделка {$payment->deal->number}: остаток ".Money::format($payment->deal->refresh()->remainingPayment()))
                            ->send();
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title('Не принято')->body(collect($e->errors())->flatten()->implode(' '))->send();
                    }
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->periodQuery()->with(['deal.manager', 'user', 'account']))
            ->defaultSort('paid_at', 'desc')
            ->columns([
                TextColumn::make('paid_at')->label('Дата')->date('d.m.Y')->sortable(),
                TextColumn::make('deal.number')
                    ->label('Сделка')
                    ->url(fn (DealPayment $record): string => DealResource::getUrl('edit', ['record' => $record->deal]))
                    ->description(fn (DealPayment $record): string => $record->deal->clientTitle())
                    ->searchable(['deals.number', 'deals.client_name', 'deals.client_company'])
                    ->weight('semibold'),
                TextColumn::make('amount')
                    ->label('Сумма')
                    ->state(fn (DealPayment $record): string => Money::format($record->amount))
                    ->color('success')
                    ->weight('semibold')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('method')->label('Способ')->badge()->color('gray'),
                TextColumn::make('account.name')->label('Счёт')->placeholder('по способу')->toggleable(),
                TextColumn::make('deal.manager.name')->label('Менеджер')->placeholder('—')->toggleable(),
                TextColumn::make('user.name')->label('Принял')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('comment')->label('Комментарий')->placeholder('—')->wrap()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('method')->label('Способ')->options(PaymentMethod::class),
                SelectFilter::make('manager')
                    ->label('Менеджер')
                    ->options(fn (): array => User::query()->whereHas('deals')->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, $id) => $q->whereHas('deal', fn (Builder $d) => $d->where('manager_id', $id)))),
            ])
            ->recordActions([
                Action::make('receipt')
                    ->label('Чек')
                    ->icon('heroicon-o-paper-clip')
                    ->color('gray')
                    ->url(fn (DealPayment $record): ?string => $record->receiptUrl())
                    ->openUrlInNewTab(),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateHeading('Поступлений пока нет')
            ->emptyStateDescription('Примите оплату кнопкой «Добавить поступление» или из карточки сделки.');
    }

    /** @return Builder<DealPayment> */
    private function periodQuery(): Builder
    {
        $query = DealPayment::query()->whereHas('deal', fn (Builder $q) => $q->sales());

        if ($this->month !== '') {
            $start = CarbonImmutable::createFromFormat('Y-m-d', $this->month.'-01')->startOfMonth();
            $query->whereBetween('paid_at', [$start->toDateString(), $start->endOfMonth()->toDateString()]);
        }

        return $query;
    }
}
