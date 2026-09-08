<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use App\Support\Validation;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
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
                    TextInput::make('phone')
                        ->label('Телефон')
                        ->tel()
                        ->telRegex(Validation::PHONE_REGEX)
                        ->mask(Validation::PHONE_MASK)
                        ->placeholder('+7 (700) 000-00-00')
                        ->rule(static fn (): Closure => Validation::phone()),

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

                    // Фото сотрудник обычно ставит себе сам в «Моём профиле»;
                    // здесь оно на случай, когда карточку заводит кадровик.
                    FileUpload::make('avatar_path')
                        ->label('Фото')
                        ->avatar()
                        ->image()
                        ->imageEditor()
                        ->directory('avatars')
                        ->disk('public')
                        ->maxSize(4096)
                        ->columnSpanFull(),
                ]),

            Section::make('Условия работы')
                ->description('Оклад и даты видит и правит только администратор.')
                ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
                ->columns(3)
                ->schema([
                    TextInput::make('salary')
                        ->label('Оклад в месяц')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->suffix(config('gravit.currency.symbol')),

                    DatePicker::make('hired_at')
                        ->label('В компании с')
                        ->displayFormat('d.m.Y')
                        ->maxDate(now()),

                    DatePicker::make('birth_date')
                        ->label('День рождения')
                        ->displayFormat('d.m.Y')
                        ->maxDate(now()->subYears(14)),
                ]),
        ]);
    }
}
