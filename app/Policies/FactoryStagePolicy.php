<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Models\FactoryStage;
use App\Models\User;
use App\Services\AccessControl;

/** Конструктор воронок: этапы несут флаги автоматизации, поэтому право отдельное. */
class FactoryStagePolicy
{
    public function viewAny(User $user): bool
    {
        return AccessControl::allows($user, Permission::SettingsStages);
    }

    public function view(User $user, FactoryStage $stage): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return AccessControl::allows($user, Permission::SettingsStages, AccessLevel::Full);
    }

    public function update(User $user, FactoryStage $stage): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, FactoryStage $stage): bool
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
