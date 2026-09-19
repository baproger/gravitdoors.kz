<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\ServerHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Проверка сервера предупреждает директора, когда диск или память на исходе,
 * и молчит, когда всё в порядке. Диск и память подменяются: тест не должен
 * зависеть от машины, на которой его гоняют.
 */
class ServerCheckTest extends TestCase
{
    use RefreshDatabase;

    private string $backups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backups = sys_get_temp_dir().'/gravit-server-check-'.uniqid();
        File::ensureDirectoryExists($this->backups);
        config()->set('gravit.server.backup_dir', $this->backups);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backups);

        parent::tearDown();
    }

    public function test_low_disk_warns_the_director(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->fakeHealth(disk: 400, memory: 600);
        $this->freshBackup();

        $this->artisan('gravit:server-check')->assertFailed();

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertStringContainsString('Свободно на диске 400 МБ', $admin->notifications()->first()->data['body'] ?? '');
    }

    public function test_low_memory_warns_the_director(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->fakeHealth(disk: 5000, memory: 80);
        $this->freshBackup();

        $this->artisan('gravit:server-check')->assertFailed();

        $this->assertStringContainsString('Доступно памяти 80 МБ', $admin->notifications()->first()->data['body'] ?? '');
    }

    public function test_missing_backup_is_reported(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->fakeHealth(disk: 5000, memory: 600);

        $this->artisan('gravit:server-check')->assertFailed();

        $this->assertStringContainsString('Резервных копий нет', $admin->notifications()->first()->data['body'] ?? '');
    }

    public function test_stale_backup_is_reported(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->fakeHealth(disk: 5000, memory: 600);
        $this->freshBackup(hoursAgo: 50);

        $this->artisan('gravit:server-check')->assertFailed();

        $this->assertStringContainsString('ночной бэкап не отработал', $admin->notifications()->first()->data['body'] ?? '');
    }

    public function test_healthy_server_is_silent(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->fakeHealth(disk: 5000, memory: 600);
        $this->freshBackup();

        $this->artisan('gravit:server-check')->assertSuccessful();

        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_unknown_memory_is_not_a_problem(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->fakeHealth(disk: 5000, memory: null);
        $this->freshBackup();

        $this->artisan('gravit:server-check')->assertSuccessful();

        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_manager_is_not_bothered_and_dry_run_sends_nothing(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        $manager = User::factory()->create(['role' => UserRole::Manager->value]);
        $this->fakeHealth(disk: 100, memory: 50);

        $this->artisan('gravit:server-check --dry')->assertFailed();
        $this->assertSame(0, $admin->notifications()->count());

        $this->artisan('gravit:server-check')->assertFailed();
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(0, $manager->notifications()->count());
    }

    public function test_thresholds_can_be_overridden_from_the_command_line(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->fakeHealth(disk: 1000, memory: 100);
        $this->freshBackup();

        $this->artisan('gravit:server-check --min-disk-mb=500 --min-memory-mb=50')->assertSuccessful();

        $this->assertSame(0, $admin->notifications()->count());
    }

    private function fakeHealth(?int $disk, ?int $memory): void
    {
        $this->app->instance(ServerHealth::class, new class($disk, $memory) extends ServerHealth
        {
            public function __construct(private readonly ?int $disk, private readonly ?int $memory) {}

            public function freeDiskMb(string $path): ?int
            {
                return $this->disk;
            }

            public function availableMemoryMb(): ?int
            {
                return $this->memory;
            }
        });
    }

    private function freshBackup(int $hoursAgo = 1): void
    {
        $file = $this->backups.'/db-20260918-030000.sql.gz';
        File::put($file, str_repeat('x', 200));
        touch($file, now()->subHours($hoursAgo)->getTimestamp());
    }
}
