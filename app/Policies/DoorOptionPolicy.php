<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Models\DoorOption;
use App\Models\User;
use App\Services\AccessControl;

/** Прайс — это деньги: кто его правит, решает матрица доступа. */
class DoorOptionPolicy
{
    public function viewAny(User $user): bool
    {
        return AccessControl::allows($user, Permission::SettingsPrice);
    }

    public function view(User $user, DoorOption $option): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return AccessControl::allows($user, Permission::SettingsPrice, AccessLevel::Full);
    }

    public function update(User $user, DoorOption $option): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, DoorOption $option): bool
    {
        return $this->create($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->create($user);
    }

    public function reorder(User $user): bool
    {
        return $this->create($user);
    }
}
