<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Services\CashLedger;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Касса и банк: остатки по счетам и журнал движений.
 * Записи сюда не вносятся руками — они приходят из платежей и расходов;
 * руками только перевод между счетами и корректировка после инвентаризации.
 */
class CashDesk extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static string|UnitEnum|null $navigationGroup = 'Финансы';

    protected static ?string $navigationLabel = 'Касса и банк';

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'cash';

    protected string $view = 'filament.pages.cash-desk';

    public static function canAccess(): bool
    {
        return auth()->user()?->role->seesMoney() ?? false;
    }

    public function getTitle(): string
    {
        return 'Касса и банк';
    }

    public function getSubheading(): ?string
    {
        return 'Остатки считаются по журналу: платежи по сделкам — приход, подтверждённые расходы — списание.';
    }

    /** @return Collection<int, CashAccount> */
    public function accounts(): Collection
    {
        return CashAccount::query()->where('is_active', true)->orderBy('type')->orderBy('id')->get();
    }

    protected function getHeaderActions(): array
    {
        $accounts = fn (): array => CashAccount::query()->where('is_active', true)->orderBy('id')->pluck('name', 'id')->all();

        return [
            Action::make('transfer')
                ->label('Перевод между счетами')
                ->icon('heroicon-o-arrows-right-left')
                ->color('gray')
                ->modalHeading('Перевод между счетами')
                ->modalDescription('Инкассация наличных в банк или снятие со счёта в кассу.')
                ->schema([
                    Select::make('from')->label('Со счёта')->options($accounts)->required()->native(false),
                    Select::make('to')->label('На счёт')->options($accounts)->required()->native(false),
                    TextInput::make('amount')->label('Сумма')->numeric()->minValue(1)->required()->suffix(config('gravit.currency.symbol')),
                    DatePicker::make('at')->label('Дата')->default(now())->maxDate(now())->displayFormat('d.m.Y')->required(),
                    TextInput::make('comment')->label('Комментарий')->maxLength(255),
                ])
                ->action(function (array $data, CashLedger $ledger): void {
                    try {
                        $ledger->transfer(
                            CashAccount::query()->findOrFail((int) $data['from']),
                            CashAccount::query()->findOrFail((int) $data['to']),
                            (float) $data['amount'],
                            CarbonImmutable::parse($data['at']),
                            $data['comment'] ?? null,
                            auth()->user(),
                        );
                        Notification::make()->success()->title('Перевод проведён')->send();
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title('Перевод не проведён')->body(collect($e->errors())->flatten()->implode(' '))->send();
                    }
                }),

            Action::make('adjust')
                ->label('Корректировка')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('warning')
                ->modalHeading('Корректировка остатка')
                ->modalDescription('После пересчёта кассы: плюс — денег больше, чем по журналу, минус — меньше. Причина обязательна и остаётся в журнале.')
                ->schema([
                    Select::make('account')->label('Счёт')->options($accounts)->required()->native(false),
                    TextInput::make('amount')->label('Сумма (со знаком)')->numeric()->required()->suffix(config('gravit.currency.symbol')),
                    DatePicker::make('at')->label('Дата')->default(now())->maxDate(now())->displayFormat('d.m.Y')->required(),
                    Textarea::make('reason')->label('Причина')->required()->rows(2),
                ])
                ->authorize(fn (): bool => auth()->user()?->role === UserRole::Admin)
                ->action(function (array $data, CashLedger $ledger): void {
                    try {
                        $ledger->adjust(
                            CashAccount::query()->findOrFail((int) $data['account']),
                            (float) $data['amount'],
                            CarbonImmutable::parse($data['at']),
                            (string) $data['reason'],
                            auth()->user(),
                        );
                        Notification::make()->success()->title('Корректировка записана')->send();
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title('Не записано')->body(collect($e->errors())->flatten()->implode(' '))->send();
                    }
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => CashMovement::query()->with(['account', 'user', 'source']))
            ->defaultSort('happened_at', 'desc')
            ->columns([
                TextColumn::make('happened_at')->label('Дата')->date('d.m.Y')->sortable(),
                TextColumn::make('account.name')->label('Счёт')->badge()->color('gray'),
                TextColumn::make('kind')
                    ->label('Операция')
                    ->state(fn (CashMovement $record): string => $record->kind())
                    ->description(fn (CashMovement $record): ?string => $record->comment),
                TextColumn::make('amount')
                    ->label('Сумма')
                    ->state(fn (CashMovement $record): string => ($record->isIn() ? '+ ' : '− ').Money::format($record->amount))
                    ->color(fn (CashMovement $record): string => $record->isIn() ? 'success' : 'danger')
                    ->weight('semibold')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('user.name')->label('Кто')->placeholder('система')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('account_id')->label('Счёт')->options(fn (): array => CashAccount::query()->pluck('name', 'id')->all()),
                Filter::make('month')
                    ->label('Этот месяц')
                    ->query(fn (Builder $query) => $query->whereBetween('happened_at', [now()->startOfMonth(), now()->endOfMonth()])),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateHeading('Движений пока нет')
            ->emptyStateDescription('Примите оплату по сделке или подтвердите расход — движение появится само.');
    }
}
