<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Filament\Pages\AccessMatrix;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Экран «Роли и доступы»: правка матрицы и защита самого экрана. */
class AccessMatrixPageTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        AccessControl::flush();
        $this->director = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->actingAs($this->director);
    }

    public function test_page_opens_for_the_director_only(): void
    {
        $this->get('/admin/access')->assertOk()->assertSee('Воронка продаж')->assertSee('Бухгалтер-финансист');

        foreach ([UserRole::Manager, UserRole::Accountant, UserRole::Hr, UserRole::Master, UserRole::Worker] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role->value]));
            $this->get('/admin/access')->assertForbidden();
        }
    }

    public function test_changing_a_cell_takes_effect_immediately(): void
    {
        $accountant = User::factory()->create(['role' => UserRole::Accountant->value]);

        $this->assertFalse(AccessControl::allows($accountant, Permission::SettingsStages));

        Livewire::test(AccessMatrix::class)
            ->call('setLevel', UserRole::Accountant->value, Permission::SettingsStages->value, AccessLevel::Read->value)
            ->assertHasNoErrors();

        $this->assertTrue(AccessControl::allows($accountant, Permission::SettingsStages));
        $this->assertSame(AccessLevel::Read, AccessControl::level(UserRole::Accountant->value, Permission::SettingsStages));
    }

    public function test_director_column_cannot_be_changed(): void
    {
        Livewire::test(AccessMatrix::class)
            ->call('setLevel', UserRole::Admin->value, Permission::SettingsAccess->value, AccessLevel::None->value);

        $this->assertSame(AccessLevel::Full, AccessControl::level(UserRole::Admin->value, Permission::SettingsAccess));
        $this->assertSame(0, RolePermission::query()->count());
    }

    public function test_unsupported_level_is_refused_without_breaking_the_page(): void
    {
        Livewire::test(AccessMatrix::class)
            ->call('setLevel', UserRole::Manager->value, Permission::WorkMaterials->value, AccessLevel::Own->value)
            ->assertHasNoErrors();

        $this->assertSame(AccessLevel::Read, AccessControl::level(UserRole::Manager->value, Permission::WorkMaterials));
    }

    public function test_reset_action_returns_the_role_to_recommended(): void
    {
        AccessControl::set(UserRole::Hr->value, Permission::FinanceCash, AccessLevel::Full);
        $this->assertTrue(AccessControl::level(UserRole::Hr->value, Permission::FinanceCash)->allows());

        Livewire::test(AccessMatrix::class)
            ->callAction('resetRole', data: ['role' => UserRole::Hr->value])
            ->assertHasNoErrors();

        $this->assertSame(AccessLevel::None, AccessControl::level(UserRole::Hr->value, Permission::FinanceCash));
    }

    /**
     * Открытая у директора вкладка не должна работать после смены пользователя:
     * право проверяется в самом действии, а не только при входе на страницу.
     */
    public function test_a_stranger_cannot_change_the_matrix_through_livewire(): void
    {
        $page = Livewire::test(AccessMatrix::class);

        $this->actingAs(User::factory()->create(['role' => UserRole::Manager->value]));

        $page->call('setLevel', UserRole::Manager->value, Permission::FinanceCash->value, AccessLevel::Full->value)
            ->assertForbidden();

        $this->assertSame(AccessLevel::None, AccessControl::level(UserRole::Manager->value, Permission::FinanceCash));
        $this->assertFalse(AccessMatrix::canAccess());
    }
}
