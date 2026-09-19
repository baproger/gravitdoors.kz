<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\AccessControl;
use App\Support\Money;
use App\Support\Uploads\PrivateFiles;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
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
                    ->disk(PrivateFiles::DISK)
                    ->visibility('private')
                    // Инициалы рисуются локально: внешний сервис аватаров в панели ни к чему.
                    ->defaultImageUrl(fn (User $record): string => self::initialsAvatar($record->name))
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
                    ->visible(fn (): bool => AccessControl::can(Permission::EmployeesFinance)),

                TextColumn::make('payout')
                    ->label('Сдельно за месяц')
                    ->state(fn (User $record): float => $record->payoutBetween(now()->startOfMonth(), now()->endOfMonth()))
                    ->formatStateUsing(fn ($state): string => Money::format($state))
                    ->alignEnd(),

                // Переключатель колонки минует политику, поэтому запрет — прямо на нём.
                ToggleColumn::make('is_active')
                    ->label('Доступ')
                    ->disabled(fn (User $record): bool => ! (auth()->user()?->can('update', $record) ?? false)),
            ])
            ->filters([
                SelectFilter::make('role')->label('Роль')->options(UserRole::class)->multiple(),

                TernaryFilter::make('is_active')
                    ->label('Доступ в систему')
                    ->placeholder('Все сотрудники')
                    ->trueLabel('Только работающие')
                    ->falseLabel('Только отключённые'),
            ])
            ->recordActions([EditAction::make()->iconButton(), DeleteAction::make()->iconButton()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    /** SVG с инициалами как data-URI — без запросов наружу. */
    private static function initialsAvatar(string $name): string
    {
        $initials = collect(preg_split('/\s+/u', trim($name)) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" rx="32" fill="#2F6FED"/>'
            .'<text x="32" y="40" font-family="system-ui, sans-serif" font-size="26" font-weight="600" fill="#FFFFFF" text-anchor="middle">'
            .htmlspecialchars($initials, ENT_QUOTES).'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
