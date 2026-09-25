<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TenderLot;
use App\Models\User;

/** Лот правит тот, кто правит его тендер. Лот со сделкой не удаляется — сделка потеряет происхождение. */
class TenderLotPolicy
{
    public function __construct(private readonly TenderPolicy $tenders) {}

    public function viewAny(User $user): bool
    {
        return $this->tenders->viewAny($user);
    }

    public function view(User $user, TenderLot $lot): bool
    {
        return $this->tenders->view($user, $lot->tender);
    }

    public function create(User $user): bool
    {
        return $this->tenders->create($user);
    }

    public function update(User $user, TenderLot $lot): bool
    {
        return $this->tenders->update($user, $lot->tender);
    }

    public function delete(User $user, TenderLot $lot): bool
    {
        return ! $lot->hasDeal() && $this->tenders->update($user, $lot->tender);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
