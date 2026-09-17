<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Tables;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Models\Expense;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('spent_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'approver', 'deal']))
            ->columns([
                TextColumn::make('spent_at')->label('Дата')->date('d.m.Y')->sortable(),
                TextColumn::make('category')->label('Категория')->badge()->sortable(),
                TextColumn::make('counterparty')
                    ->label('Кому')
                    ->placeholder('—')
                    ->description(fn (Expense $record): ?string => $record->comment)
                    ->searchable(['counterparty', 'comment'])
                    ->wrap(),
                TextColumn::make('amount')
                    ->label('Сумма')
                    ->state(fn (Expense $record): string => Money::format($record->amount))
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('method')->label('Способ')->badge()->color('gray')->toggleable(),
                TextColumn::make('deal.number')->label('Сделка')->placeholder('—')->toggleable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->description(fn (Expense $record): ?string => $record->status === ExpenseStatus::Rejected
                        ? $record->rejection_reason
                        : ($record->approver?->name)),
                TextColumn::make('user.name')->label('Внёс')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')->label('Категория')->options(ExpenseCategory::class)->multiple(),
                SelectFilter::make('method')->label('Способ')->options(PaymentMethod::class),
                Filter::make('month')
                    ->label('Этот месяц')
                    ->query(fn (Builder $query) => $query->whereBetween('spent_at', [now()->startOfMonth(), now()->endOfMonth()])),
            ])
            ->recordActions([
                Action::make('receipt')
                    ->label('Чек')
                    ->icon('heroicon-o-paper-clip')
                    ->color('gray')
                    ->url(fn (Expense $record): ?string => $record->receiptUrl())
                    ->openUrlInNewTab()
                    ->visible(fn (Expense $record): bool => $record->receipt_path !== null),

                Action::make('approve')
                    ->label('Подтвердить')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Expense $record): string => 'Подтвердить расход '.Money::format($record->amount).'?')
                    ->modalDescription('Расход попадёт в финансовую сводку. Отменить подтверждение можно только отклонением с причиной.')
                    ->authorize(fn (Expense $record): bool => auth()->user()?->can('approve', $record) ?? false)
                    ->visible(fn (Expense $record): bool => ! $record->isApproved())
                    ->action(function (Expense $record): void {
                        try {
                            $record->forceFill(['status' => ExpenseStatus::Approved, 'rejection_reason' => null])->save();
                            Notification::make()->success()->title('Расход подтверждён')->send();
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title('Не подтверждён')->body(collect($e->errors())->flatten()->implode(' '))->send();
                        }
                    }),

                Action::make('reject')
                    ->label('Отклонить')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Отклонить расход?')
                    ->schema([Textarea::make('reason')->label('Причина')->required()->rows(2)])
                    ->authorize(fn (Expense $record): bool => auth()->user()?->can('approve', $record) ?? false)
                    ->visible(fn (Expense $record): bool => $record->status !== ExpenseStatus::Rejected)
                    ->action(function (Expense $record, array $data): void {
                        $record->forceFill(['status' => ExpenseStatus::Rejected, 'rejection_reason' => $data['reason']])->save();
                        Notification::make()->success()->title('Расход отклонён')->send();
                    }),

                EditAction::make()->iconButton(),

                DeleteAction::make()
                    ->iconButton()
                    ->authorize(fn (Expense $record): bool => (auth()->user()?->can('delete', $record) ?? false) && $record->canBeDeleted()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('approveSelected')
                        ->label('Подтвердить выбранные')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->authorize(fn (): bool => auth()->user()?->can('approve', new Expense) ?? false)
                        ->action(function (Collection $records): void {
                            /** @var Collection<int, Expense> $records */
                            $done = 0;
                            $failed = [];

                            DB::transaction(function () use ($records, &$done, &$failed): void {
                                foreach ($records as $expense) {
                                    if ($expense->isApproved()) {
                                        continue;
                                    }

                                    try {
                                        $expense->forceFill(['status' => ExpenseStatus::Approved, 'rejection_reason' => null])->save();
                                        $done++;
                                    } catch (ValidationException) {
                                        $failed[] = $expense->counterparty ?: Money::format($expense->amount);
                                    }
                                }
                            });

                            Notification::make()->success()->title("Подтверждено: {$done}")->send();

                            if ($failed !== []) {
                                Notification::make()->warning()->title('Без чека: '.count($failed))
                                    ->body(implode(', ', array_slice($failed, 0, 5)))->persistent()->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->emptyStateHeading('Расходов пока нет')
            ->emptyStateDescription('Нажмите «Новый расход»: аренда, налоги, реклама, транспорт — всё, что не идёт через сделки.');
    }
}
