<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Models\Deal;
use App\Models\User;
use App\Services\AccessControl;

/**
 * Кто и что делает со сделками и нарядами.
 *
 * Роли здесь не перечисляются: уровень доступа берётся из реестра прав, а он
 * правится в «Настройки → Роли и доступы». У записи два измерения — список
 * сделок (`work.deals`) и воронка, к которой она относится: сделку продаж
 * ведут через `work.sales_kanban`, наряд — через `work.factory_kanban`.
 */
class DealPolicy
{
    public function viewAny(User $user): bool
    {
        return AccessControl::allows($user, Permission::WorkDeals);
    }

    public function view(User $user, Deal $deal): bool
    {
        $level = $this->levelFor($user, $deal);

        return $level->allows() && (! $level->isOwnOnly() || $this->owns($user, $deal));
    }

    /** Заводит сделки тот, кто ведёт воронку продаж. */
    public function create(User $user): bool
    {
        return $this->weakest(
            AccessControl::levelFor($user, Permission::WorkDeals),
            AccessControl::levelFor($user, Permission::WorkSalesKanban),
        )->canWrite();
    }

    public function update(User $user, Deal $deal): bool
    {
        $level = $this->levelFor($user, $deal);

        if (! $level->canWrite()) {
            return false;
        }

        return $level === AccessLevel::Full || $this->owns($user, $deal);
    }

    /**
     * Удаление — отдельное право: это разрыв связи с нарядом и историей.
     * Живой наряд и сделка с нарядом в цеху не удаляются вовсе (`canBeDeleted`).
     */
    public function delete(User $user, Deal $deal): bool
    {
        return AccessControl::allows($user, Permission::DealsDelete, AccessLevel::Full) && $deal->canBeDeleted();
    }

    public function deleteAny(User $user): bool
    {
        return AccessControl::allows($user, Permission::DealsDelete, AccessLevel::Full);
    }

    /**
     * Двигать по воронке: решает право на саму воронку, а не на список.
     * Поэтому цех двигает наряды, не имея полного доступа к карточкам сделок.
     */
    public function move(User $user, Deal $deal): bool
    {
        $level = AccessControl::levelFor($user, $this->pipelineRight($deal));

        if (! $level->canWrite()) {
            return false;
        }

        return $level === AccessLevel::Full || $this->owns($user, $deal);
    }

    /**
     * Записать результат замера.
     *
     * Отдельное полномочие, а не `update`: у замерщика нет доступа ни к списку
     * сделок, ни к воронке — карточка ему не открывается, — но цифры со своего
     * выезда он внести обязан, иначе они и дальше пойдут голосом.
     */
    public function measure(User $user, Deal $deal): bool
    {
        if ($deal->isFactoryOrder() || $deal->status_id->isClosed() || $deal->measured_at === null) {
            return false;
        }

        return $user->role->doesSurveys() || $this->update($user, $deal);
    }

    /** Отказ клиента: сделка закрывается со статусом «Отменена». */
    public function cancel(User $user, Deal $deal): bool
    {
        if ($deal->isFactoryOrder()) {
            return false;
        }

        $level = AccessControl::levelFor($user, Permission::DealsCancel);

        if (! $level->canWrite()) {
            return false;
        }

        return $level === AccessLevel::Full || $this->owns($user, $deal);
    }

    /** Отметка оплаты и блокировка отгрузки — полномочие финансов. */
    public function flagPayment(User $user, Deal $deal): bool
    {
        return ! $deal->isFactoryOrder()
            && AccessControl::allows($user, Permission::DealsPaymentFlag, AccessLevel::Full);
    }

    /** Ответственный менеджер сделки; для наряда — менеджер его сделки. */
    public function owns(User $user, Deal $deal): bool
    {
        $ownerId = $deal->salesDeal()->manager_id;

        // Сделка без ответственного не должна пропасть из системы: пока менеджер
        // не назначен, её видит каждый, кто работает на уровне «только свои».
        return $ownerId === null || $ownerId === $user->id;
    }

    /** Слабейший из двух уровней: список и воронка ограничивают друг друга. */
    private function levelFor(User $user, Deal $deal): AccessLevel
    {
        return $this->weakest(
            AccessControl::levelFor($user, Permission::WorkDeals),
            AccessControl::levelFor($user, $this->pipelineRight($deal)),
        );
    }

    private function pipelineRight(Deal $deal): Permission
    {
        return $deal->isFactoryOrder() ? Permission::WorkFactoryKanban : Permission::WorkSalesKanban;
    }

    private function weakest(AccessLevel $a, AccessLevel $b): AccessLevel
    {
        return $a->weight() <= $b->weight() ? $a : $b;
    }
}
