<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Auth\EditProfile;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\ProductionLog;
use App\Models\User;
use Database\Seeders\DoorOptionSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Карточка сотрудника и свой профиль. */
class EmployeeProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);
    }

    public function test_employee_card_opens_with_metrics(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        $master = User::factory()->create(['role' => UserRole::Master->value, 'salary' => 220_000]);

        $this->actingAs($admin)
            ->get("/admin/users/{$master->id}")
            ->assertOk()
            ->assertSee('Показатели')
            ->assertSee('Активность за неделю')
            ->assertSee($master->name);
    }

    public function test_payroll_sums_salary_and_piecework(): void
    {
        $master = User::factory()->create(['role' => UserRole::Master->value, 'salary' => 200_000]);
        ProductionLog::factory()->done()->create(['worker_id' => $master->id, 'payout' => 7_500]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]));

        $payroll = Livewire::test(ViewUser::class, ['record' => $master->id])->instance()->payroll();

        $this->assertEqualsWithDelta(200_000, $payroll['salary'], 0.01);
        $this->assertEqualsWithDelta(7_500, $payroll['piecework'], 0.01);
        $this->assertEqualsWithDelta(207_500, $payroll['total'], 0.01);
    }

    public function test_workshop_staff_cannot_open_a_colleagues_card(): void
    {
        $worker = User::factory()->create(['role' => UserRole::Worker->value]);
        $colleague = User::factory()->create(['role' => UserRole::Master->value, 'salary' => 999_111]);

        $this->actingAs($worker);

        $this->get("/admin/users/{$colleague->id}")->assertForbidden();
        // Список сотрудников цеху тоже не положен.
        $this->get('/admin/users')->assertForbidden();
    }

    public function test_colleague_card_is_for_hr_and_the_director_only(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager->value]);
        $colleague = User::factory()->create(['role' => UserRole::Master->value, 'salary' => 999_111]);

        // Свою карточку открывает каждый, чужую — только кадры и директор.
        $this->actingAs($manager);
        $this->get("/admin/users/{$manager->id}")->assertOk();
        $this->get("/admin/users/{$colleague->id}")->assertForbidden();

        $hr = User::factory()->create(['role' => UserRole::Hr->value]);
        $this->actingAs($hr);
        $this->get("/admin/users/{$colleague->id}")->assertOk()->assertSee('999 111');

        // Бухгалтер видит карточку и оклад, но список сотрудников — только для чтения.
        $accountant = User::factory()->create(['role' => UserRole::Accountant->value]);
        $this->actingAs($accountant);
        $this->assertTrue(Livewire::test(ViewUser::class, ['record' => $colleague->id])->instance()->canSeeMoney());
        $this->assertFalse($accountant->can('update', $colleague));
    }

    public function test_everyone_can_open_their_own_card_and_see_their_salary(): void
    {
        $master = User::factory()->create(['role' => UserRole::Master->value, 'salary' => 220_000]);

        $this->actingAs($master);

        $this->get("/admin/users/{$master->id}")->assertOk();

        $this->assertTrue(
            Livewire::test(ViewUser::class, ['record' => $master->id])->instance()->canSeeMoney(),
            'Свою зарплату сотрудник видеть должен',
        );
    }

    public function test_anyone_can_change_their_own_avatar(): void
    {
        Storage::fake('public');

        $worker = User::factory()->create(['role' => UserRole::Worker->value]);
        $this->actingAs($worker);

        $file = UploadedFile::fake()->image('me.jpg', 400, 400);

        Livewire::test(EditProfile::class)
            ->fillForm(['avatar_path' => [$file], 'name' => $worker->name, 'email' => $worker->email])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNotNull($worker->refresh()->avatar_path, 'Аватар не сохранился');
        Storage::disk('public')->assertExists($worker->avatar_path);
    }

    public function test_profile_does_not_expose_role_or_salary(): void
    {
        $worker = User::factory()->create(['role' => UserRole::Worker->value, 'salary' => 150_000]);
        $this->actingAs($worker);

        // В профиле нет полей роли и оклада — их правит только администратор
        // в разделе «Сотрудники».
        Livewire::test(EditProfile::class)
            ->assertFormFieldExists('avatar_path')
            ->assertFormFieldDoesNotExist('role')
            ->assertFormFieldDoesNotExist('salary')
            ->assertFormFieldDoesNotExist('is_active');
    }

    public function test_avatar_shows_up_in_the_panel(): void
    {
        $user = User::factory()->create(['avatar_path' => 'avatars/me.jpg']);

        $this->assertStringContainsString('avatars/me.jpg', (string) $user->getFilamentAvatarUrl());
        $this->assertNull(User::factory()->create(['avatar_path' => null])->getFilamentAvatarUrl());
    }

    public function test_initials_fall_back_when_there_is_no_photo(): void
    {
        $user = User::factory()->create(['name' => 'Ерлан Мастер', 'avatar_path' => null]);

        $this->assertSame('ЕМ', $user->initials());
    }

    public function test_tenure_is_human_readable(): void
    {
        $this->assertSame('7 мес.', User::factory()->create(['hired_at' => now()->subMonths(7)])->tenure());
        $this->assertSame('2 г. 3 мес.', User::factory()->create(['hired_at' => now()->subMonths(27)])->tenure());
        $this->assertNull(User::factory()->create(['hired_at' => null])->tenure());
    }
}
