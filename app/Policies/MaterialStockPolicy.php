<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Models\MaterialStock;
use App\Models\User;
use App\Services\AccessControl;

class MaterialStockPolicy
{
    public function viewAny(User $user): bool
    {
        return AccessControl::allows($user, Permission::WorkMaterials);
    }

    public function view(User $user, MaterialStock $material): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return AccessControl::allows($user, Permission::WorkMaterials, AccessLevel::Full);
    }

    public function update(User $user, MaterialStock $material): bool
    {
        return $this->create($user);
    }

    /**
     * Материал с движениями или привязанными позициями прайса не удаляется:
     * с ним ушла бы история списаний, и отмена наряда не смогла бы ничего вернуть.
     */
    public function delete(User $user, MaterialStock $material): bool
    {
        return $this->create($user) && $material->canBeDeleted();
    }

    public function deleteAny(User $user): bool
    {
        return $this->create($user);
    }
}
