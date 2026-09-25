<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Models\Deal;
use App\Models\Tender;
use App\Models\TenderLot;
use App\Models\User;
use App\Services\AccessControl;

/**
 * Тендеры — право `work.tenders`. «Только свои» — свои и ничьи, как у сделок:
 * тендер без ответственного не должен пропасть из системы.
 */
class TenderPolicy
{
    public function viewAny(User $user): bool
    {
        return AccessControl::allows($user, Permission::WorkTenders);
    }

    public function view(User $user, Tender $tender): bool
    {
        $level = AccessControl::levelFor($user, Permission::WorkTenders);

        return $level->allows() && (! $level->isOwnOnly() || $this->owns($user, $tender));
    }

    public function create(User $user): bool
    {
        return AccessControl::levelFor($user, Permission::WorkTenders)->canWrite();
    }

    public function update(User $user, Tender $tender): bool
    {
        $level = AccessControl::levelFor($user, Permission::WorkTenders);

        return $level->canWrite() && ($level === AccessLevel::Full || $this->owns($user, $tender));
    }

    public function delete(User $user, Tender $tender): bool
    {
        return $this->update($user, $tender) && $tender->canBeDeleted();
    }

    public function deleteAny(User $user): bool
    {
        return AccessControl::allows($user, Permission::WorkTenders, AccessLevel::Full);
    }

    /**
     * Завести сделку из лота: нужен и тендер, и право заводить сделки.
     * Директора `Gate::before` пропускает мимо — правила лота проверяет сервис.
     */
    public function createDeal(User $user, Tender $tender, TenderLot $lot): bool
    {
        return $this->update($user, $tender)
            && $user->can('create', Deal::class)
            && ! $lot->hasDeal();
    }

    public function owns(User $user, Tender $tender): bool
    {
        return $tender->manager_id === null || $tender->manager_id === $user->id;
    }
}
