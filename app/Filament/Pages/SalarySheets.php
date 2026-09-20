<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\AccessLevel;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\SalarySheetStatus;
use App\Models\CashAccount;
use App\Models\Role;
use App\Models\SalarySheet;
use App\Services\AccessControl;
use App\Services\PayrollService;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
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
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Зарплата: ведомость за месяц по всем сотрудникам.
 * Оклад + сдельно + бонусы − удержания − авансы = к выплате. Выплата — расход и касса.
 */
class SalarySheets extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Финансы';

    protected static ?string $navigationLabel = 'Зарплата';

    protected static ?int $navigationSort = 75;

    protected static ?string $slug = 'salary';

    protected string $view = 'filament.pages.salary-sheets';

    #[Url]
    public string $month = '';

    public static function canAccess(): bool
    {
        return AccessControl::can(Permission::FinanceSalarySheets);
    }

    public function mount(): void
    {
        if ($this->month === '') {
            $this->month = now()->format('Y-m');
        }
    }

    public function getTitle(): string
    {
        return 'Зарплата — ведомость';
    }

    public function getSubheading(): ?string
    {
        return 'Оклад + сдельно + бонусы − удержания − авансы. Черновик пересчитывается, утверждённая ведомость зафиксирована.';
    }

    public function updatedMonth(): void
    {
        $this->resetTable();
    }

    /** @return array<string, string> */
    public function monthOptions(): array
    {
        $options = [];

        foreach (range(0, 11) as $back) {
            $date = now()->subMonths($back);
            $options[$date->format('Y-m')] = mb_convert_case($date->translatedFormat('F Y'), MB_CASE_TITLE);
        }

        return $options;
    }

    /** @return array<string, float|int> */
    public function totals(): array
    {
        $sheets = SalarySheet::query()->where('month', $this->month)->get();

        return [
            'count' => $sheets->count(),
            'total' => round((float) $sheets->sum('total'), 2),
            'paid' => round((float) $sheets->sum('paid_amount'), 2),
            'remaining' => round((float) $sheets->sum(fn (SalarySheet $s): float => $s->remaining()), 2),
            'drafts' => $sheets->where('status', SalarySheetStatus::Draft)->count(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('build')
                ->label('Сформировать / пересчитать')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading(fn (): string => 'Сформировать ведомость за '.($this->monthOptions()[$this->month] ?? $this->month).'?')
                ->modalDescription('Черновики пересчитаются из оклада, закрытых этапов и утверждённых бонусов. Утверждённые ведомости не изменятся.')
                ->authorize(fn (): bool => AccessControl::can(Permission::FinanceSalarySheets, AccessLevel::Full))
                ->action(function (PayrollService $payroll): void {
                    $sheets = $payroll->build($this->month);
                    $this->resetTable();
                    Notification::make()->success()->title('Ведомость сформирована')->body("Строк: {$sheets->count()}")->send();
                }),

            Action::make('approveAll')
                ->label('Утвердить все черновики')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('После утверждения цифры фиксируются: пересчёт и правка удержаний недоступны.')
                ->authorize(fn (): bool => AccessControl::can(Permission::FinanceSalarySheets, AccessLevel::Full)
                    && AccessControl::can(Permission::FinanceApprove, AccessLevel::Full))
                ->visible(fn (): bool => SalarySheet::query()->where('month', $this->month)->where('status', SalarySheetStatus::Draft->value)->exists())
                ->action(function (PayrollService $payroll): void {
                    $drafts = SalarySheet::query()->where('month', $this->month)->where('status', SalarySheetStatus::Draft->value)->get();
                    $drafts->each(fn (SalarySheet $s) => $payroll->approve($s, auth()->user()));
                    $this->resetTable();
                    Notification::make()->success()->title("Утверждено: {$drafts->count()}")->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SalarySheet::query()->where('month', $this->month)->with('user'))
            ->defaultSort('total', 'desc')
            ->columns([
                TextColumn::make('user.name')->label('Сотрудник')->weight('semibold')->description(fn (SalarySheet $s): string => $s->user->role->getLabel())->searchable(),
                TextColumn::make('salary')->label('Оклад')->state(fn (SalarySheet $s): string => Money::format($s->salary))->alignEnd(),
                TextColumn::make('piecework')->label('Сдельно')->state(fn (SalarySheet $s): string => Money::format($s->piecework))->alignEnd(),
                TextColumn::make('bonuses')->label('Бонусы')->state(fn (SalarySheet $s): string => Money::format($s->bonuses))->alignEnd(),
                TextColumn::make('deductions')
                    ->label('Удержания / авансы')
                    ->state(fn (SalarySheet $s): string => ((float) $s->deductions + (float) $s->advances) > 0 ? '− '.Money::format((float) $s->deductions + (float) $s->advances) : '—')
                    ->description(fn (SalarySheet $s): ?string => $s->comment)
                    ->alignEnd(),
                TextColumn::make('total')->label('К выплате')->state(fn (SalarySheet $s): string => Money::format($s->total))->weight('semibold')->alignEnd()->sortable(),
                TextColumn::make('paid_amount')->label('Выплачено')->state(fn (SalarySheet $s): string => Money::format($s->paid_amount))->color('success')->alignEnd(),
                TextColumn::make('status')->label('Статус')->badge(),
            ])
            ->filters([
                SelectFilter::make('user_id')
                    ->label('Сотрудник')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->multiple(),

                SelectFilter::make('role')
                    ->label('Должность')
                    ->options(fn (): array => Role::assignable()->pluck('name', 'code')->all())
                    ->multiple()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['values'] ?? [],
                        fn (Builder $q, array $codes) => $q->whereHas('user', fn (Builder $u) => $u->whereIn('role', $codes))
                    )),

                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(SalarySheetStatus::class)
                    ->multiple(),

                // Главный вопрос бухгалтера в конце месяца: кому ещё не выплатили.
                Filter::make('unpaid')
                    ->label('Не выплачено полностью')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereColumn('paid_amount', '<', 'total')),

                Filter::make('with_deductions')
                    ->label('С удержаниями или авансом')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $q) => $q
                        ->where('deductions', '>', 0)->orWhere('advances', '>', 0))),
            ])
            ->filtersFormColumns(2)
            ->recordActions([
                Action::make('adjust')
                    ->label('Удержания')
                    ->icon('heroicon-o-minus-circle')
                    ->color('gray')
                    ->modalHeading(fn (SalarySheet $record): string => "Удержания и авансы: {$record->user->name}")
                    ->authorize(fn (SalarySheet $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->visible(fn (SalarySheet $record): bool => $record->isDraft())
                    ->schema([
                        TextInput::make('deductions')->label('Удержания')->numeric()->minValue(0)->default(fn (SalarySheet $r): float => (float) $r->deductions)->suffix(config('gravit.currency.symbol')),
                        TextInput::make('advances')->label('Авансы (уже выданы)')->numeric()->minValue(0)->default(fn (SalarySheet $r): float => (float) $r->advances)->suffix(config('gravit.currency.symbol')),
                        TextInput::make('comment')->label('Комментарий')->default(fn (SalarySheet $r): ?string => $r->comment)->maxLength(255),
                    ])
                    ->action(function (SalarySheet $record, array $data, PayrollService $payroll): void {
                        try {
                            $payroll->adjust($record, (float) ($data['deductions'] ?? 0), (float) ($data['advances'] ?? 0), $data['comment'] ?? null);
                            Notification::make()->success()->title('Пересчитано: к выплате '.Money::format($record->total))->send();
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title('Не сохранено')->body(collect($e->errors())->flatten()->implode(' '))->send();
                        }
                    }),

                Action::make('approve')
                    ->label('Утвердить')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->authorize(fn (SalarySheet $record): bool => auth()->user()?->can('approve', $record) ?? false)
                    ->visible(fn (SalarySheet $record): bool => $record->isDraft())
                    ->action(function (SalarySheet $record, PayrollService $payroll): void {
                        $payroll->approve($record, auth()->user());
                        Notification::make()->success()->title('Ведомость утверждена')->send();
                    }),

                Action::make('pay')
                    ->label('Выплатить')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->modalHeading(fn (SalarySheet $record): string => "Выплата: {$record->user->name} — остаток ".Money::format($record->remaining()))
                    ->modalDescription('Создаст расход «Зарплата» и списание со счёта.')
                    ->authorize(fn (SalarySheet $record): bool => auth()->user()?->can('pay', $record) ?? false)
                    ->visible(fn (SalarySheet $record): bool => ! $record->isDraft() && $record->remaining() > 0)
                    ->schema([
                        TextInput::make('amount')->label('Сумма')->numeric()->minValue(1)->required()->default(fn (SalarySheet $r): float => $r->remaining())->suffix(config('gravit.currency.symbol')),
                        Select::make('method')->label('Способ')->options(PaymentMethod::class)->default(PaymentMethod::Cash->value)->required()->native(false),
                        Select::make('account_id')
                            ->label('Счёт')
                            ->options(fn (): array => CashAccount::query()->where('is_active', true)->orderBy('id')->pluck('name', 'id')->all())
                            ->visible(fn (): bool => CashAccount::query()->where('is_active', true)->count() > 2)
                            ->native(false),
                        DatePicker::make('paid_at')->label('Дата')->default(now())->maxDate(now())->displayFormat('d.m.Y')->required(),
                    ])
                    ->action(function (SalarySheet $record, array $data, PayrollService $payroll): void {
                        try {
                            $method = $data['method'] instanceof PaymentMethod ? $data['method'] : PaymentMethod::from((string) $data['method']);
                            $payroll->pay($record, (float) $data['amount'], $method, CarbonImmutable::parse($data['paid_at']), isset($data['account_id']) ? (int) $data['account_id'] : null, auth()->user());
                            $record->refresh();
                            Notification::make()->success()
                                ->title($record->status === SalarySheetStatus::Paid ? 'Выплачено полностью' : 'Выплата проведена')
                                ->body('Остаток: '.Money::format($record->remaining()))
                                ->send();
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title('Не выплачено')->body(collect($e->errors())->flatten()->implode(' '))->send();
                        }
                    }),
            ])
            ->paginated(false)
            ->emptyStateHeading('Ведомости за этот месяц нет')
            ->emptyStateDescription('Нажмите «Сформировать»: оклады, закрытые этапы и утверждённые бонусы соберутся сами.');
    }
}
