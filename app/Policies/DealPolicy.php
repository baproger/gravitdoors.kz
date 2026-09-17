<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Deal;
use App\Models\User;

/**
 * Кто и что делает со сделками.
 *
 * Цех видит наряды, но не сделки продаж и не деньги — ограничение по воронке
 * живёт в DealResource::getEloquentQuery(), здесь только права на действия.
 */
class DealPolicy
{
    /** Замерщику список сделок не положен: его работа — уведомления о замерах. */
    public function viewAny(User $user): bool
    {
        return $user->role !== UserRole::Surveyor;
    }

    public function view(User $user, Deal $deal): bool
    {
        // Рабочему и мастеру доступны только производственные наряды:
        // по прямой ссылке на сделку продаж они получат 403.
        return $user->role->seesMoney() || $deal->isFactoryOrder();
    }

    public function create(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }

    public function update(User $user, Deal $deal): bool
    {
        if (in_array($user->role, [UserRole::Admin, UserRole::Manager], true)) {
            return true;
        }

        // Мастер правит только наряды своего цеха — комментарии, исполнителей.
        return $user->role === UserRole::Master && $deal->isFactoryOrder();
    }

    /**
     * Удаление сделки — только администратор: это разрыв связи с нарядом и историей.
     * Пока наряд в цеху, сделку не удалить — иначе на заводе остался бы наряд-сирота
     * с невозвращёнными материалами; сначала отмена наряда. Сам наряд не удаляется
     * вовсе, пока он не закрыт: он отменяется.
     */
    public function delete(User $user, Deal $deal): bool
    {
        return $user->role === UserRole::Admin && $deal->canBeDeleted();
    }

    public function deleteAny(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    /**
     * Двигать по воронке: продажи — своих сделок, цех — своих нарядов.
     * Менеджер наряды не закрывает: закрытие этапа — это сдельная оплата,
     * а её начисляет цех.
     */
    public function move(User $user, Deal $deal): bool
    {
        return match ($user->role) {
            UserRole::Admin => true,
            UserRole::Manager => ! $deal->isFactoryOrder(),
            UserRole::Master => $deal->isFactoryOrder(),
            default => false,
        };
    }
}
