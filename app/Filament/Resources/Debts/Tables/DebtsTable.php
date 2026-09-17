<?php

declare(strict_types=1);

namespace App\Filament\Resources\Debts\Tables;

use App\Enums\DebtCategory;
use App\Enums\DebtStatus;
use App\Enums\PaymentMethod;
use App\Models\CashAccount;
use App\Models\Debt;
use App\Services\CashLedger;
use App\Support\Money;
use App\Support\Plural;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DebtsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('due_at')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->recordClasses(fn (Debt $record): ?string => $record->isOverdue() ? 'dl-row--overdue' : null)
            ->columns([
                TextColumn::make('counterparty')
                    ->label('Кому')
                    ->description(fn (Debt $record): ?string => $record->comment)
                    ->searchable(['counterparty', 'comment'])
                    ->weight('semibold')
                    ->wrap(),
                TextColumn::make('category')->label('Категория')->badge()->color('gray'),
                TextColumn::make('amount')->label('Долг')->state(fn (Debt $r): string => Money::format($r->amount))->alignEnd()->sortable(),
                TextColumn::make('paid_amount')->label('Выплачено')->state(fn (Debt $r): string => Money::format($r->paid_amount))->color('success')->alignEnd(),
                TextColumn::make('remaining')
                    ->label('Остаток')
                    ->state(fn (Debt $r): string => Money::format($r->remaining()))
                    ->color(fn (Debt $r): string => $r->remaining() > 0 ? 'danger' : 'gray')
                    ->weight('semibold')
                    ->alignEnd(),
                TextColumn::make('due_at')
                    ->label('Срок')
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->description(fn (Debt $r): ?string => $r->isOverdue() ? 'просрочен '.$r->overdueDays().' '.Plural::choose($r->overdueDays(), 'день', 'дня', 'дней') : null)
                    ->color(fn (Debt $r): ?string => $r->isOverdue() ? 'danger' : null)
                    ->sortable(),
                TextColumn::make('status')->label('Статус')->badge(),
            ])
            ->filters([
                SelectFilter::make('category')->label('Категория')->options(DebtCategory::class),
            ])
            ->recordActions([
                Action::make('pay')
                    ->label('Оплатить')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->modalHeading(fn (Debt $record): string => "Платёж: {$record->counterparty} — остаток ".Money::format($record->remaining()))
                    ->modalDescription('Создаст подтверждённый расход и списание со счёта.')
                    ->authorize(fn (Debt $record): bool => auth()->user()?->can('pay', $record) ?? false)
                    ->visible(fn (Debt $record): bool => ! $record->status->isClosed())
                    ->schema([
                        TextInput::make('amount')->label('Сумма')->numeric()->minValue(1)->required()->default(fn (Debt $record): float => $record->remaining())->suffix(config('gravit.currency.symbol')),
                        Select::make('method')->label('Способ')->options(PaymentMethod::class)->default(PaymentMethod::Transfer->value)->required()->native(false),
                        Select::make('account_id')
                            ->label('Счёт')
                            ->options(fn (): array => CashAccount::query()->where('is_active', true)->orderBy('id')->pluck('name', 'id')->all())
                            ->visible(fn (): bool => CashAccount::query()->where('is_active', true)->count() > 2)
                            ->native(false),
                        DatePicker::make('paid_at')->label('Дата')->default(now())->maxDate(now())->displayFormat('d.m.Y')->required(),
                        FileUpload::make('receipt_path')
                            ->label('Чек / платёжка')
                            ->required(fn (Debt $record): bool => $record->category->expenseCategory()->requiresReceipt())
                            ->directory('expenses')
                            ->disk('public')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                            ->maxSize(10240)
                            ->columnSpanFull(),
                    ])
                    ->action(function (Debt $record, array $data, CashLedger $ledger): void {
                        try {
                            $ledger->payDebt(
                                $record,
                                (float) $data['amount'],
                                $data['method'] instanceof PaymentMethod ? $data['method'] : PaymentMethod::from((string) $data['method']),
                                CarbonImmutable::parse($data['paid_at']),
                                isset($data['account_id']) ? (int) $data['account_id'] : null,
                                $data['receipt_path'] ?? null,
                                auth()->user(),
                            );

                            $record->refresh();
                            Notification::make()->success()
                                ->title($record->status === DebtStatus::Paid ? 'Долг погашен' : 'Платёж проведён')
                                ->body('Остаток: '.Money::format($record->remaining()))
                                ->send();
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title('Платёж не проведён')->body(collect($e->errors())->flatten()->implode(' '))->send();
                        }
                    }),

                Action::make('cancel')
                    ->label('Отменить')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Долг больше не учитывается. Уже проведённые платежи остаются расходами.')
                    ->authorize(fn (Debt $record): bool => auth()->user()?->can('pay', $record) ?? false)
                    ->visible(fn (Debt $record): bool => ! $record->status->isClosed())
                    ->action(function (Debt $record): void {
                        $record->forceFill(['status' => DebtStatus::Cancelled])->save();
                        Notification::make()->success()->title('Долг отменён')->send();
                    }),

                Action::make('document')
                    ->label('Документ')
                    ->icon('heroicon-o-paper-clip')
                    ->color('gray')
                    ->url(fn (Debt $record): ?string => $record->document_path ? Storage::disk('public')->url($record->document_path) : null)
                    ->openUrlInNewTab()
                    ->visible(fn (Debt $record): bool => $record->document_path !== null),

                EditAction::make()->iconButton(),

                DeleteAction::make()
                    ->iconButton()
                    ->authorize(fn (Debt $record): bool => (auth()->user()?->can('delete', $record) ?? false) && $record->canBeDeleted()),
            ])
            ->emptyStateHeading('Долгов нет')
            ->emptyStateDescription('Заведите долг, когда получили счёт от поставщика, арендодателя или налоговой.');
    }
}
