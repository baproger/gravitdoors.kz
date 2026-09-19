<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Реестр прав: рекомендованная матрица, отличия в базе, правила изменения. */
class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        AccessControl::flush();
    }

    public function test_recommended_matrix_matches_the_brief(): void
    {
        $cases = [
            // право, роль, ожидаемый уровень
            [Permission::WorkSalesKanban, UserRole::Manager, AccessLevel::Own],
            [Permission::WorkSalesKanban, UserRole::Accountant, AccessLevel::Read],
            [Permission::WorkSalesKanban, UserRole::Master, AccessLevel::None],
            [Permission::WorkFactoryKanban, UserRole::Master, AccessLevel::Full],
            [Permission::WorkFactoryKanban, UserRole::Manager, AccessLevel::Read],
            [Permission::WorkDeals, UserRole::Accountant, AccessLevel::Full],
            [Permission::WorkDeals, UserRole::Hr, AccessLevel::None],
            [Permission::FinanceOverview, UserRole::Accountant, AccessLevel::Full],
            [Permission::FinanceOverview, UserRole::Hr, AccessLevel::None],
            [Permission::FinanceOverview, UserRole::Manager, AccessLevel::None],
            [Permission::FinanceSalarySheets, UserRole::Hr, AccessLevel::Full],
            [Permission::FinanceCash, UserRole::Hr, AccessLevel::None],
            [Permission::SettingsEmployees, UserRole::Hr, AccessLevel::Full],
            [Permission::SettingsEmployees, UserRole::Accountant, AccessLevel::Read],
            [Permission::SettingsStages, UserRole::Accountant, AccessLevel::None],
            [Permission::SettingsWorkshop, UserRole::Master, AccessLevel::Full],
            [Permission::KanbanMoney, UserRole::Manager, AccessLevel::Own],
            [Permission::KanbanTotals, UserRole::Manager, AccessLevel::None],
            [Permission::DealsDelete, UserRole::Manager, AccessLevel::None],
            [Permission::DealsCancel, UserRole::Manager, AccessLevel::Own],
            [Permission::FactoryMaterials, UserRole::Worker, AccessLevel::Full],
        ];

        foreach ($cases as [$permission, $role, $expected]) {
            $this->assertSame(
                $expected,
                AccessControl::level($role->value, $permission),
                "{$role->getLabel()} → {$permission->getLabel()}",
            );
        }
    }

    public function test_every_role_can_see_own_salary_and_director_has_everything(): void
    {
        foreach (UserRole::cases() as $role) {
            $this->assertSame(AccessLevel::Full, AccessControl::level($role->value, Permission::FinanceMySalary), $role->getLabel());
        }

        foreach (Permission::cases() as $permission) {
            $this->assertSame(AccessLevel::Full, AccessControl::level(UserRole::Admin->value, $permission), $permission->getLabel());
        }
    }

    public function test_override_wins_over_the_recommended_level(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager->value]);

        $this->assertFalse(AccessControl::allows($manager, Permission::FinanceOverview));

        AccessControl::set(UserRole::Manager->value, Permission::FinanceOverview, AccessLevel::Read);

        $this->assertTrue(AccessControl::allows($manager, Permission::FinanceOverview));
        $this->assertFalse(AccessControl::allows($manager, Permission::FinanceOverview, AccessLevel::Full));
        $this->assertTrue(AccessControl::isOverridden(UserRole::Manager->value, Permission::FinanceOverview));
    }

    public function test_setting_the_recommended_level_removes_the_row(): void
    {
        AccessControl::set(UserRole::Manager->value, Permission::FinanceOverview, AccessLevel::Full);
        $this->assertSame(1, RolePermission::query()->count());

        AccessControl::set(UserRole::Manager->value, Permission::FinanceOverview, AccessLevel::None);

        $this->assertSame(0, RolePermission::query()->count(), 'Копия значения по умолчанию в базе не нужна');
        $this->assertFalse(AccessControl::isOverridden(UserRole::Manager->value, Permission::FinanceOverview));
    }

    public function test_reset_returns_the_role_to_recommended(): void
    {
        AccessControl::set(UserRole::Accountant->value, Permission::SettingsStages, AccessLevel::Full);
        AccessControl::set(UserRole::Accountant->value, Permission::WorkDeals, AccessLevel::None);

        AccessControl::reset(UserRole::Accountant->value);

        $this->assertSame(AccessLevel::None, AccessControl::level(UserRole::Accountant->value, Permission::SettingsStages));
        $this->assertSame(AccessLevel::Full, AccessControl::level(UserRole::Accountant->value, Permission::WorkDeals));
    }

    public function test_director_cannot_be_limited(): void
    {
        $this->expectException(ValidationException::class);

        AccessControl::set(UserRole::Admin->value, Permission::SettingsAccess, AccessLevel::None);
    }

    public function test_unsupported_level_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        // «Только свои» не бывает у склада: у остатка нет владельца.
        AccessControl::set(UserRole::Manager->value, Permission::WorkMaterials, AccessLevel::Own);
    }

    public function test_inactive_user_has_no_access(): void
    {
        $fired = User::factory()->create(['role' => UserRole::Accountant->value, 'is_active' => false]);

        $this->assertFalse(AccessControl::allows($fired, Permission::FinanceCash));
        $this->assertFalse(AccessControl::allows(null, Permission::FinanceMySalary));
    }

    public function test_stale_row_falls_back_to_the_recommended_level(): void
    {
        RolePermission::query()->create([
            'role' => UserRole::Manager->value,
            'permission' => Permission::WorkMaterials->value,
            'level' => 'own', // уровень, который право больше не поддерживает
        ]);
        AccessControl::flush();

        $this->assertSame(AccessLevel::Read, AccessControl::level(UserRole::Manager->value, Permission::WorkMaterials));
    }

    public function test_every_permission_has_a_label_and_at_least_two_levels(): void
    {
        foreach (Permission::cases() as $permission) {
            $this->assertNotSame('', $permission->getLabel());
            $this->assertContains($permission->group(), Permission::groups());
            $this->assertGreaterThanOrEqual(2, count($permission->levels()));
            $this->assertContains(AccessLevel::None, $permission->levels());

            foreach (UserRole::cases() as $role) {
                $this->assertTrue(
                    $permission->supports($permission->default($role->value)),
                    "Рекомендованный уровень {$role->getLabel()} → {$permission->getLabel()} не поддерживается правом",
                );
            }
        }
    }
}
