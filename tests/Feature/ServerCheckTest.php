<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\ServerHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
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

    /**
     * Пароль из README на боевом сервере — повод разбудить директора.
     *
     * Репозиторий публичный, и в нём прямо написано, что у демо-записей пароль
     * `password`. Если такая запись окажется на бою — при переносе базы с
     * разработки или правке руками, — вход открыт всем, кто умеет читать.
     */
    public function test_weak_password_wakes_the_director(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $admin = User::factory()->create(['role' => UserRole::Admin->value, 'password' => Hash::make('С-в-о-й-2026!')]);
        User::factory()->create(['email' => 'demo@gravit.kz', 'password' => Hash::make('password')]);
        $this->fakeHealth(disk: 5000, memory: 600);
        $this->freshBackup();

        $this->artisan('gravit:server-check')->assertFailed();

        $body = $admin->notifications()->first()->data['body'] ?? '';
        $this->assertStringContainsString('demo@gravit.kz', $body);
        $this->assertStringNotContainsString('password', $body, 'Сам пароль в уведомлении повторять незачем');
    }

    /** Заблокированный сотрудник со слабым паролем войти не может — и тревоги не поднимает. */
    public function test_disabled_account_with_a_weak_password_is_not_reported(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $admin = User::factory()->create(['role' => UserRole::Admin->value, 'password' => Hash::make('С-в-о-й-2026!')]);
        User::factory()->create(['email' => 'old@gravit.kz', 'password' => Hash::make('password'), 'is_active' => false]);
        $this->fakeHealth(disk: 5000, memory: 600);
        $this->freshBackup();

        // Проверка всё равно найдёт несобранный кэш — в тестах его и не собирают.
        // Важно другое: про заблокированного сотрудника в письме ни слова.
        $this->artisan('gravit:server-check')->assertFailed();

        $body = (string) ($admin->notifications()->first()->data['body'] ?? '');
        $this->assertStringNotContainsString('old@gravit.kz', $body);
        $this->assertStringNotContainsString('Пароль угадывается', $body);
    }

    /**
     * Несобранный кэш на бою — тихая потеря скорости, и её надо заметить.
     *
     * Если в Plesk не прописано действие при развёртывании, `artisan optimize`
     * не запускается: сайт работает, но каждый запрос разбирает настройки
     * заново. Снаружи это не увидеть — только изнутри.
     */
    public function test_missing_caches_are_reported_on_the_live_server(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $admin = User::factory()->create(['role' => UserRole::Admin->value, 'password' => Hash::make('С-в-о-й-2026!')]);
        $this->fakeHealth(disk: 5000, memory: 600);
        $this->freshBackup();

        $this->artisan('gravit:server-check')->assertFailed();

        $body = (string) ($admin->notifications()->first()->data['body'] ?? '');
        $this->assertStringContainsString('Не собран кэш', $body);
        $this->assertStringContainsString('plesk-deploy.sh', $body, 'Директору нужно не описание беды, а что нажать');
    }

    /** На разработке кэш мешает: правка настроек не видна, пока не сбросишь. */
    public function test_missing_caches_are_not_reported_in_development(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->fakeHealth(disk: 5000, memory: 600);
        $this->freshBackup();

        $this->artisan('gravit:server-check')->assertSuccessful();

        $this->assertSame(0, $admin->notifications()->count());
    }

    /** На разработке `password` у всех по замыслу сидера — проверка молчит. */
    public function test_weak_passwords_are_ignored_outside_production(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        User::factory()->create(['email' => 'demo@gravit.kz', 'password' => Hash::make('password')]);
        $this->fakeHealth(disk: 5000, memory: 600);
        $this->freshBackup();

        $this->artisan('gravit:server-check')->assertSuccessful();

        $this->assertSame(0, $admin->notifications()->count());
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
