<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label('Имя')->required(),
                    TextInput::make('email')->label('E-mail')->email()->required()->unique(ignoreRecord: true),
                    TextInput::make('phone')->label('Телефон')->tel(),

                    Select::make('role')
                        ->label('Роль')
                        ->options(UserRole::class)
                        ->default(UserRole::Manager->value)
                        ->required()
                        ->native(false),

                    TextInput::make('password')
                        ->label('Пароль')
                        ->password()
                        ->revealable()
                        ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Hash::make($state) : null)
                        // При редактировании пустое поле означает «пароль не меняем»,
                        // иначе каждое сохранение карточки затирало бы пароль.
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->required(fn (?string $operation): bool => $operation === 'create'),

                    Toggle::make('is_active')
                        ->label('Доступ в систему')
                        ->helperText('Выключенный сотрудник не войдёт в панель.')
                        ->default(true),
                ]),
        ]);
    }
}
