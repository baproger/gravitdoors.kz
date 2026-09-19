<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Models\ProductionLog;
use App\Models\User;
use App\Services\AccessControl;

/** Работы цеха: кто их видит и правит, решает право на воронку завода. */
class ProductionLogPolicy
{
    public function viewAny(User $user): bool
    {
        return AccessControl::allows($user, Permission::WorkFactoryKanban);
    }

    public function view(User $user, ProductionLog $log): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return AccessControl::allows($user, Permission::WorkFactoryKanban, AccessLevel::Full);
    }

    public function update(User $user, ProductionLog $log): bool
    {
        return $this->create($user);
    }

    /** Удаление работы меняет зарплату задним числом — только полный доступ. */
    public function delete(User $user, ProductionLog $log): bool
    {
        return $this->create($user);
    }
}
