<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MaterialStock;
use App\Models\User;
use Database\Seeders\FactoryStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class]);
    }

    public function test_low_stock_reaches_management_as_a_notification(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        MaterialStock::factory()->belowLimit()->create(['name' => 'Лист стальной 1.5 мм']);

        $this->artisan('gravit:daily-check')->assertSuccessful();

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertStringContainsString('Заканчиваются материалы', $admin->notifications()->first()->data['title'] ?? '');
    }

    public function test_worker_is_not_bothered_with_stock_alerts(): void
    {
        $worker = User::factory()->create(['role' => UserRole::Worker->value]);
        MaterialStock::factory()->belowLimit()->create();

        $this->artisan('gravit:daily-check')->assertSuccessful();

        $this->assertSame(0, $worker->notifications()->count());
    }

    public function test_healthy_stock_produces_no_noise(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        MaterialStock::factory()->create(['quantity' => 500, 'min_limit' => 10]);

        $this->artisan('gravit:daily-check')->assertSuccessful();

        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_dry_run_sends_nothing(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        MaterialStock::factory()->belowLimit()->create();

        $this->artisan('gravit:daily-check', ['--dry' => true])->assertSuccessful();

        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_inactive_users_are_skipped(): void
    {
        $fired = User::factory()->create(['role' => UserRole::Admin->value, 'is_active' => false]);
        MaterialStock::factory()->belowLimit()->create();

        $this->artisan('gravit:daily-check')->assertSuccessful();

        $this->assertSame(0, $fired->notifications()->count());
    }
}
