<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Models\CashAccount;
use App\Models\DoorOption;
use App\Models\FactoryStage;
use App\Models\User;
use Database\Seeders\GravitFactoryStagesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Установка на боевой сервер: одна команда даёт рабочую систему без демо-данных,
 * а повторный запуск ничего не ломает.
 */
class InstallCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_install_seeds_references_and_creates_the_director(): void
    {
        $this->artisan('gravit:install --name="Иван Директор" --email=director@gravit.kz --password=secret123')
            ->assertSuccessful();

        $admin = User::query()->where('email', 'director@gravit.kz')->firstOrFail();
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue(Hash::check('secret123', $admin->password));
        $this->assertTrue($admin->isAdmin());

        $this->assertTrue(FactoryStage::query()->ofPipeline(PipelineType::Sales)->exists(), 'этапы продаж');
        $this->assertSame(13, FactoryStage::query()->ofPipeline(PipelineType::Factory)->active()->count(), 'боевая цепочка цеха');
        $this->assertTrue(FactoryStage::query()->where('code', GravitFactoryStagesSeeder::COMPLETES)->where('completes_production', true)->exists());
        $this->assertSame(2, CashAccount::query()->count(), 'касса и банк');
        $this->assertTrue(DoorOption::query()->exists(), 'прайс');

        // Без демо-сделок и демо-сотрудников.
        $this->assertSame(1, User::query()->count());
        $this->assertDatabaseCount('deals', 0);
    }

    public function test_second_run_keeps_owner_edits_and_does_not_add_a_second_director(): void
    {
        $this->artisan('gravit:install --name=Д --email=director@gravit.kz --password=secret123')->assertSuccessful();

        $stage = FactoryStage::query()->where('code', 'welding')->firstOrFail();
        $stage->update(['name' => 'Сварка по-нашему', 'operation_cost' => 7500]);
        $option = DoorOption::query()->firstOrFail();
        $option->update(['price' => 999_999]);

        $this->artisan('gravit:install --name=Второй --email=second@gravit.kz --password=secret123')
            ->expectsOutputToContain('Справочники уже есть')
            ->expectsOutputToContain('Директор уже есть: director@gravit.kz')
            ->assertSuccessful();

        $this->assertSame('Сварка по-нашему', $stage->fresh()->name);
        $this->assertEquals(7500, $stage->fresh()->operation_cost);
        $this->assertEquals(999_999, $option->fresh()->price);
        $this->assertSame(1, User::query()->where('role', UserRole::Admin->value)->count());
        $this->assertDatabaseMissing('users', ['email' => 'second@gravit.kz']);
    }

    public function test_install_refuses_a_weak_password_and_a_missing_email(): void
    {
        // --no-interaction: как в deploy-скрипте, где спросить некого.
        $this->artisan('gravit:install --no-interaction --name=Д --email=director@gravit.kz --password=123')
            ->expectsOutputToContain('Директор не создан')
            ->assertFailed();

        $this->artisan('gravit:install --no-interaction --name=Д --password=secret123')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
        // Справочники при этом уже на месте: их можно было заполнить.
        $this->assertTrue(FactoryStage::query()->exists());
    }
}
