<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Filament\Pages\AccessMatrix;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Справочник ролей: директор заводит свои роли из панели.
 *
 * Раньше роли были перечнем в коде, и «Кладовщика» без правки исходников
 * завести было нельзя. Теперь роль — строка в `roles`, а её код уходит в
 * `users.role` и `role_permissions.role`.
 */
class RoleDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        $this->director = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->actingAs($this->director);
    }

    public function test_seven_built_in_roles_are_in_the_directory(): void
    {
        $this->assertCount(count(UserRole::cases()), Role::cached());

        foreach (UserRole::cases() as $case) {
            $role = Role::byCode($case->value);

            $this->assertNotNull($role, $case->value);
            $this->assertTrue($role->is_system, $case->value);
            $this->assertSame($case->getLabel(), $role->name);
            $this->assertSame($case->doesSurveys(), $role->doesSurveys(), $case->value);
            $this->assertSame($case->isFactoryStaff(), $role->isFactoryStaff(), $case->value);
        }
    }

    public function test_director_creates_a_role_by_copying_permissions(): void
    {
        $this->createRole(['name' => 'Кладовщик', 'copy_from' => UserRole::Master->value]);

        $role = Role::query()->where('name', 'Кладовщик')->firstOrFail();

        $this->assertFalse($role->is_system);
        $this->assertSame('kladovshhik', $role->code);

        // Права пришли с начальника производства.
        $this->assertSame(AccessLevel::Full, AccessControl::level($role->code, Permission::WorkMaterials));
        $this->assertSame(AccessLevel::Full, AccessControl::level($role->code, Permission::WorkFactoryKanban));
        $this->assertSame(AccessLevel::None, AccessControl::level($role->code, Permission::FinanceCash));
    }

    public function test_a_new_role_cannot_become_a_second_director(): void
    {
        $this->createRole(['name' => 'Зам', 'copy_from' => UserRole::Accountant->value]);
        $code = Role::query()->where('name', 'Зам')->value('code');

        // Ни копированием, ни руками право на саму матрицу не выдаётся.
        $this->assertSame(AccessLevel::None, AccessControl::level($code, Permission::SettingsAccess));

        Livewire::test(AccessMatrix::class)
            ->call('setLevel', $code, Permission::SettingsAccess->value, AccessLevel::Full->value);

        $this->assertSame(AccessLevel::None, AccessControl::level($code, Permission::SettingsAccess));
    }

    public function test_new_role_works_on_a_real_employee(): void
    {
        $this->createRole(['name' => 'Кладовщик', 'copy_from' => UserRole::Master->value]);
        $code = Role::query()->where('name', 'Кладовщик')->value('code');

        $keeper = User::factory()->create(['role' => $code]);

        $this->assertSame($code, $keeper->roleCode());
        $this->assertSame('Кладовщик', $keeper->role?->name);
        $this->assertFalse($keeper->isAdmin());
        $this->assertTrue(AccessControl::allows($keeper, Permission::WorkMaterials, AccessLevel::Full));
        $this->assertFalse(AccessControl::allows($keeper, Permission::FinanceCash));

        $this->actingAs($keeper)->get('/admin/material-stocks')->assertOk();
        $this->actingAs($keeper)->get('/admin/access')->assertForbidden();
    }

    /** Галочка «ездит на замеры» включает роль в рассылку, а не её код в коде. */
    public function test_behaviour_toggles_drive_the_automations(): void
    {
        $this->createRole(['name' => 'Выездной', 'copy_from' => UserRole::Manager->value, 'does_surveys' => true]);
        $code = Role::query()->where('name', 'Выездной')->value('code');

        $this->assertContains($code, Role::codesWith('does_surveys'));
        $this->assertNotContains($code, Role::codesWith('is_factory_staff'));

        $scout = User::factory()->create(['role' => $code]);
        $this->assertTrue($scout->role?->doesSurveys());
        $this->assertFalse($scout->isFactoryStaff());
    }

    public function test_built_in_role_can_be_renamed_but_not_deleted(): void
    {
        $surveyor = Role::byCode(UserRole::Surveyor->value);

        $this->assertFalse($surveyor->canBeDeleted());

        Livewire::test(AccessMatrix::class)
            ->mountAction('editRole')
            ->setActionData([
                'role' => UserRole::Surveyor->value,
                'name' => 'Замерщик-монтажник',
                'hint' => 'Ездит на замеры и на монтаж',
                'color' => 'success',
                'does_surveys' => true,
                'is_factory_staff' => false,
            ])
            ->callMountedAction()
            ->assertHasNoErrors();

        AccessControl::flush();

        $this->assertSame('Замерщик-монтажник', Role::byCode(UserRole::Surveyor->value)?->name);
        // Код не менялся — сотрудники и права на месте.
        $this->assertTrue(Role::byCode(UserRole::Surveyor->value)->is_system);
    }

    public function test_role_with_employees_is_not_deleted(): void
    {
        $this->createRole(['name' => 'Кладовщик', 'copy_from' => UserRole::Master->value]);
        $code = Role::query()->where('name', 'Кладовщик')->value('code');
        User::factory()->create(['role' => $code]);

        AccessControl::flush();

        $this->assertFalse(Role::byCode($code)->canBeDeleted());
    }

    public function test_empty_custom_role_is_deleted_with_its_permissions(): void
    {
        $this->createRole(['name' => 'Временный', 'copy_from' => UserRole::Manager->value]);
        $code = Role::query()->where('name', 'Временный')->value('code');

        Livewire::test(AccessMatrix::class)
            ->mountAction('deleteRole')
            ->setActionData(['role' => $code])
            ->callMountedAction()
            ->assertHasNoErrors();

        AccessControl::flush();

        $this->assertNull(Role::byCode($code));
        $this->assertDatabaseMissing('role_permissions', ['role' => $code]);
    }

    /** Русское название кода не даёт — код всё равно должен получиться. */
    public function test_role_code_is_unique_and_never_empty(): void
    {
        $this->createRole(['name' => 'Кладовщик', 'copy_from' => UserRole::Master->value]);
        $this->createRole(['name' => 'Кладовщик', 'copy_from' => UserRole::Master->value]);
        $this->createRole(['name' => '中文', 'copy_from' => UserRole::Manager->value]);

        $codes = Role::query()->where('is_system', false)->pluck('code');

        $this->assertCount(3, $codes);
        $this->assertCount(3, $codes->unique());
        $this->assertTrue($codes->every(fn (string $code): bool => $code !== '' && mb_strlen($code) <= Role::CODE_MAX));
    }

    /** Страницу открывает только директор — и на самом действии стоит та же проверка. */
    public function test_a_stranger_cannot_create_a_role(): void
    {
        // Форма заполняется под директором, а нажимается уже чужими руками:
        // скрытая кнопка — не защита, право проверяется на самом действии.
        $page = Livewire::test(AccessMatrix::class)
            ->mountAction('createRole')
            ->setActionData([
                'name' => 'Самозванец',
                'copy_from' => UserRole::Admin->value,
                'color' => 'gray',
                'hint' => null,
                'does_surveys' => false,
                'is_factory_staff' => false,
            ]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Manager->value]));

        $this->get('/admin/access')->assertForbidden();
        $page->callMountedAction()->assertForbidden();

        $this->assertSame(0, Role::query()->where('is_system', false)->count());
    }

    /** @param array<string, mixed> $data */
    private function createRole(array $data): void
    {
        Livewire::test(AccessMatrix::class)
            ->mountAction('createRole')
            ->setActionData($data + ['color' => 'gray', 'hint' => null, 'does_surveys' => false, 'is_factory_staff' => false])
            ->callMountedAction()
            ->assertHasNoErrors();

        AccessControl::flush();
    }
}
