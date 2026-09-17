<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bonuses\Tables;

use App\Enums\BonusStatus;
use App\Models\Bonus;
use App\Models\User;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BonusesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'deal', 'author']))
            ->columns([
                TextColumn::make('month')->label('Месяц')->sortable(),
                TextColumn::make('user.name')->label('Сотрудник')->searchable()->weight('semibold'),
                TextColumn::make('reason')->label('За что')->description(fn (Bonus $r): ?string => $r->deal?->number)->wrap()->searchable(),
                TextColumn::make('amount')->label('Сумма')->state(fn (Bonus $r): string => Money::format($r->amount))->alignEnd()->sortable(),
                TextColumn::make('status')->label('Статус')->badge(),
                TextColumn::make('author.name')->label('Предложил')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('user_id')->label('Сотрудник')->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('month')->label('Месяц')->options(fn (): array => Bonus::query()->distinct()->orderByDesc('month')->pluck('month', 'month')->all()),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Утвердить')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Бонус попадёт в зарплатную ведомость за указанный месяц.')
                    ->authorize(fn (Bonus $record): bool => auth()->user()?->can('approve', $record) ?? false)
                    ->visible(fn (Bonus $record): bool => ! $record->isApproved())
                    ->action(function (Bonus $record): void {
                        $record->forceFill(['status' => BonusStatus::Approved])->save();
                        Notification::make()->success()->title('Бонус утверждён')->send();

                        Notification::make()
                            ->title('Бонус утверждён: '.Money::format($record->amount))
                            ->body($record->reason.' — войдёт в ведомость за '.$record->month)
                            ->icon('heroicon-o-gift')
                            ->success()
                            ->sendToDatabase($record->user);
                    }),
                Action::make('revoke')
                    ->label('Снять утверждение')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Бонус вернётся на утверждение. Если ведомость за месяц уже утверждена, её цифры не изменятся.')
                    ->authorize(fn (Bonus $record): bool => auth()->user()?->can('approve', $record) ?? false)
                    ->visible(fn (Bonus $record): bool => $record->isApproved())
                    ->action(function (Bonus $record): void {
                        $record->forceFill(['status' => BonusStatus::Pending])->save();
                        Notification::make()->success()->title('Утверждение снято')->send();
                    }),
                EditAction::make()->iconButton(),
                DeleteAction::make()
                    ->iconButton()
                    ->authorize(fn (Bonus $record): bool => (auth()->user()?->can('delete', $record) ?? false) && $record->canBeDeleted()),
            ])
            ->emptyStateHeading('Бонусов пока нет')
            ->emptyStateDescription('Нажмите «Начислить бонус»: сотрудник, месяц, сумма и за что.');
    }
}
