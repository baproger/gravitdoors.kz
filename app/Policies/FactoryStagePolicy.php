<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\FactoryStage;
use App\Models\User;

/** Конструктор воронок меняет только администратор: этапы несут флаги автоматизации. */
class FactoryStagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function view(User $user, FactoryStage $stage): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, FactoryStage $stage): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, FactoryStage $stage): bool
    {
        return $this->viewAny($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function reorder(User $user): bool
    {
        return $this->viewAny($user);
    }
}
