<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;

/**
 * Вход в панель: витрина слева, форма справа.
 *
 * Наследуемся от страницы Filament и меняем **только разметку**: вся логика
 * входа остаётся её — лимит в пять попыток за минуту, второй фактор, коды
 * восстановления. Свой `authenticate()` здесь был бы способом тихо потерять
 * эту защиту (её стережёт `SecurityHardeningTest`).
 *
 * Слой `base` вместо `simple`: простая раскладка центрирует одну карточку по
 * экрану, а нам нужны две половины во всю высоту.
 */
class Login extends BaseLogin
{
    protected static string $layout = 'filament-panels::components.layout.base';

    protected string $view = 'filament.auth.login';

    /** Заголовок рисует сама страница — Filament не должен печатать второй. */
    public function getHeading(): string
    {
        return '';
    }

    /**
     * «Адрес электронной почты» — длинно для короткой формы входа.
     *
     * Правим подпись у готового поля, а не собираем своё: у филаментовского
     * уже есть проверка, автозаполнение и автофокус.
     */
    protected function getEmailFormComponent(): Component
    {
        $email = parent::getEmailFormComponent();

        if ($email instanceof TextInput) {
            $email->label('Email');
        }

        return $email;
    }
}
