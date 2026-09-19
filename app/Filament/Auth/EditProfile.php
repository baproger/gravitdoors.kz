<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Support\Validation;
use Closure;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Свой профиль.
 *
 * Сотрудник правит только то, что про него самого: имя, контакты, аватар и
 * пароль. Роль, оклад и доступ остаются за администратором — иначе рабочий мог
 * бы выписать себе оклад директора прямо из профиля.
 */
class EditProfile extends BaseEditProfile
{
    public function getTitle(): string
    {
        return 'Мой профиль';
    }

    public static function getLabel(): string
    {
        return 'Мой профиль';
    }

    public function form(Schema $schema): Schema
    {
        // Подписи над полями, а не сбоку: со сдвигом влево форма растягивалась
        // на всю ширину экрана ради трёх полей.
        return $schema->inlineLabel(false)->components([
            Section::make('Кто я')
                ->columns(2)
                ->schema([
                    FileUpload::make('avatar_path')
                        ->label('Фото')
                        ->avatar()
                        ->image()
                        ->imageEditor()
                        ->imageEditorAspectRatios(['1:1'])
                        ->directory('avatars')
                        ->maxSize(4096)
                        ->helperText('Квадратное фото до 4 МБ. Видно коллегам в карточке сотрудника.')
                        ->columnSpanFull(),

                    $this->getNameFormComponent()->label('Имя'),

                    TextInput::make('phone')
                        ->label('Телефон')
                        ->tel()
                        ->telRegex(Validation::PHONE_REGEX)
                        ->mask(Validation::PHONE_MASK)
                        ->placeholder('+7 (700) 000-00-00')
                        ->rule(static fn (): Closure => Validation::phone()),

                    $this->getEmailFormComponent()->label('E-mail')->columnSpanFull(),
                ]),

            Section::make('Пароль')
                ->description('Оставьте поля пустыми, если менять пароль не нужно.')
                ->columns(2)
                ->schema([
                    $this->getPasswordFormComponent()->label('Новый пароль'),
                    $this->getPasswordConfirmationFormComponent()->label('Повторите пароль'),
                ]),
        ]);
    }
}
