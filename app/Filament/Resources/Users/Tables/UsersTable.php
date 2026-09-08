<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Enums\UserRole;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->recordUrl(fn (User $record): string => UserResource::getUrl('view', ['record' => $record]))
            ->columns([
                ImageColumn::make('avatar_path')
                    ->label('')
                    ->circular()
                    ->disk('public')
                    ->defaultImageUrl(fn (User $record): string => 'https://ui-avatars.com/api/?name='
                        .urlencode($record->name).'&color=FFFFFF&background=2F6FED')
                    ->visibleFrom('sm'),

                TextColumn::make('name')
                    ->label('Сотрудник')
                    ->description(fn (User $record): ?string => $record->phone)
                    ->searchable(['name', 'email', 'phone'])
                    ->weight('semibold'),

                TextColumn::make('email')->label('E-mail')->copyable()->toggleable()->visibleFrom('md'),

                TextColumn::make('role')->label('Роль')->badge(),

                TextColumn::make('salary')
                    ->label('Оклад')
                    ->formatStateUsing(fn ($state): string => Money::format($state))
                    ->alignEnd()
                    ->visibleFrom('lg')
                    ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false),

                TextColumn::make('payout')
                    ->label('Сдельно за месяц')
                    ->state(fn (User $record): float => $record->payoutBetween(now()->startOfMonth(), now()->endOfMonth()))
                    ->formatStateUsing(fn ($state): string => Money::format($state))
                    ->alignEnd(),

                ToggleColumn::make('is_active')->label('Доступ'),
            ])
            ->filters([
                SelectFilter::make('role')->label('Роль')->options(UserRole::class),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
