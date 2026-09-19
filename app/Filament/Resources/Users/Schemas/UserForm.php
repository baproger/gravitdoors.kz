<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Setting;
use App\Services\AccessControl;
use App\Support\Validation;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

/**
 * Карточка сотрудника в модалке.
 *
 * Фото стоит первым и занимает одну колонку из четырёх: раньше оно уходило
 * во всю ширину и выталкивало остальные поля вниз, из-за чего форма не
 * помещалась на экран.
 */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        // Схема в одну колонку: по умолчанию секции встают рядом и в модалке
        // сжимают поля до нечитаемых обрубков вроде «12 000» → «1».
        return $schema->columns(1)->components([
            Section::make('Сотрудник')
                ->columns(4)
                ->schema([
                    FileUpload::make('avatar_path')
                        ->label('Фото')
                        ->avatar()
                        ->image()
                        ->imageEditor()
                        ->imageEditorAspectRatios(['1:1'])
                        ->directory('avatars')
                        ->disk('public')
                        ->maxSize(4096)
                        ->columnSpan(1),

                    Grid::make(3)
                        ->columnSpan(3)
                        ->schema([
                            TextInput::make('name')
                                ->label('Имя')
                                ->required()
                                ->minLength(2)
                                ->maxLength(255)
                                ->columnSpan(2),

                            Select::make('role')
                                ->label('Роль')
                                ->options(UserRole::class)
                                ->default(UserRole::Manager->value)
                                ->required()
                                ->native(false),

                            TextInput::make('email')
                                ->label('E-mail')
                                ->email()
                                ->required()
                                ->unique(ignoreRecord: true)
                                ->columnSpan(2),

                            TextInput::make('phone')
                                ->label('Телефон')
                                ->tel()
                                ->telRegex(Validation::PHONE_REGEX)
                                ->mask(Validation::PHONE_MASK)
                                ->placeholder('+7 (700) 000-00-00')
                                ->rule(static fn (): Closure => Validation::phone()),

                            TextInput::make('password')
                                ->label('Пароль')
                                ->password()
                                ->revealable()
                                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Hash::make($state) : null)
                                // При редактировании пустое поле означает «пароль не меняем»,
                                // иначе каждое сохранение карточки затирало бы пароль.
                                ->dehydrated(fn (?string $state): bool => filled($state))
                                ->required(fn (?string $operation): bool => $operation === 'create')
                                ->helperText(fn (?string $operation): ?string => $operation === 'edit'
                                    ? 'Пусто — пароль не меняется'
                                    : null)
                                ->columnSpan(2),

                            Toggle::make('is_active')
                                ->label('Доступ в систему')
                                ->helperText('Выключенный в панель не войдёт')
                                ->default(true)
                                ->inline(false),
                        ]),
                ]),

            Section::make('Условия работы')
                ->description('Видит и правит только администратор')
                ->visible(fn (): bool => AccessControl::can(Permission::EmployeesFinance, AccessLevel::Full))
                ->columns(3)
                ->schema([
                    TextInput::make('salary')
                        ->label('Оклад в месяц')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->suffix(config('gravit.currency.symbol')),

                    TextInput::make('bonus_percent')
                        ->label('Бонус со сделки, %')
                        ->helperText(fn (): string => 'Пусто — общая ставка '.rtrim(rtrim(number_format(Setting::managerBonusPercent(), 2, '.', ''), '0'), '.').' % из настроек финансов')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->step(0.1)
                        ->suffix('%'),

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
