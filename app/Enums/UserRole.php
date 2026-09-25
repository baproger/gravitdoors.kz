<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Базовые роли — те, на кодах которых держится код системы.
 *
 * Сами роли живут в справочнике `roles` (`App\Models\Role`) и заводятся
 * директором из панели. Этот перечень остался как список «встроенных»: на
 * `admin` завязан `Gate::before`, на остальных — сидеры и первичная установка.
 * Из него же таблица заполняется при миграции.
 *
 * Значения менять нельзя: они лежат в `users.role` и `role_permissions.role`.
 * Что роль может делать — решает не этот перечень, а реестр прав
 * (`App\Services\AccessControl`), который правится в «Настройки → Роли и доступы».
 */
enum UserRole: string implements HasColor, HasLabel
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Accountant = 'accountant';
    case Hr = 'hr';
    case Surveyor = 'surveyor';
    case Master = 'master';
    case Worker = 'worker';
    case B2b = 'b2b';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Директор',
            self::Manager => 'Менеджер продаж',
            self::Accountant => 'Бухгалтер-финансист',
            self::Hr => 'HR-директор',
            self::Surveyor => 'Замерщик',
            self::Master => 'Начальник производства',
            self::Worker => 'Рабочий цеха',
            self::B2b => 'Менеджер B2B',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Admin => 'danger',
            self::Manager => 'info',
            self::Accountant => 'primary',
            self::Hr => 'violet',
            self::Surveyor => 'success',
            self::Master => 'warning',
            self::Worker => 'gray',
            self::B2b => 'teal',
        };
    }

    /** Короткое пояснение к роли — в карточке сотрудника и в матрице прав. */
    public function hint(): string
    {
        return match ($this) {
            self::Admin => 'Полный доступ ко всем разделам и настройкам',
            self::Manager => 'Свои сделки, передача в производство, своя зарплата',
            self::Accountant => 'Деньги: счета, поступления, расходы, касса, долги, зарплата',
            self::Hr => 'Сотрудники, ведомости, бонусы',
            self::Surveyor => 'Замеры по уведомлениям и своя зарплата',
            self::Master => 'Наряды цеха, склад, экран цеха',
            self::Worker => 'Планшет цеха и своя зарплата',
            self::B2b => 'Юрлица и тендеры: свои тендеры, лоты и сделки, своя зарплата',
        };
    }

    /** Выезжает ли роль на замеры — им уходят уведомления о назначенной дате. */
    public function doesSurveys(): bool
    {
        return in_array($this, [self::Surveyor, self::Master], true);
    }

    /**
     * Роль цеха: ей начисляется сдельная оплата за закрытый этап.
     * Не про доступ к экранам — это решает реестр прав.
     */
    public function isFactoryStaff(): bool
    {
        return in_array($this, [self::Master, self::Worker], true);
    }
}
