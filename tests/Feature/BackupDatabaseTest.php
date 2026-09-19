<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\ServerHealth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Бэкап на маленьком диске: база хранится глубже, чем файлы, и архив файлов
 * не делается, если после него места не останется.
 *
 * Без RefreshDatabase: тот держит тест в транзакции, а `VACUUM INTO` внутри
 * транзакции SQLite не выполняет. Вместо этого команда получает свою временную
 * SQLite-базу, и тест одинаково проходит на прогоне с SQLite и с MySQL —
 * `mysqldump` он не трогает, это внешняя программа сервера.
 */
class BackupDatabaseTest extends TestCase
{
    private string $backups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backups = sys_get_temp_dir().'/gravit-backup-'.uniqid();
        File::ensureDirectoryExists($this->backups);
        config()->set('gravit.server.backup_dir', $this->backups);

        $source = $this->backups.'/source.sqlite';
        touch($source);
        config()->set('database.connections.backup_source', ['driver' => 'sqlite', 'database' => $source, 'prefix' => '']);
        config()->set('database.default', 'backup_source');
        DB::purge('backup_source');
    }

    protected function tearDown(): void
    {
        DB::purge('backup_source');
        File::deleteDirectory($this->backups);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_backup_writes_database_snapshot_and_files_archive(): void
    {
        $this->fakeDisk(freeMb: 100_000);

        $this->artisan('gravit:backup')->assertSuccessful();

        $this->assertCount(1, File::glob($this->backups.'/db-*.sqlite'));
        $this->assertCount(1, File::glob($this->backups.'/files-*.tar.gz'));
    }

    public function test_files_archive_is_skipped_when_disk_is_almost_full(): void
    {
        $this->fakeDisk(freeMb: 1000);
        config()->set('gravit.server.min_free_disk_mb', 1500);

        $this->artisan('gravit:backup')
            ->expectsOutputToContain('Архив файлов пропущен')
            ->assertSuccessful();

        $this->assertCount(1, File::glob($this->backups.'/db-*.sqlite'), 'база сохраняется всегда');
        $this->assertCount(0, File::glob($this->backups.'/files-*.tar.gz'));
    }

    public function test_database_and_files_rotate_separately(): void
    {
        $this->fakeDisk(freeMb: 100_000);

        foreach ([1, 2, 3, 4] as $day) {
            Carbon::setTestNow(Carbon::create(2026, 9, $day, 3));
            $this->artisan('gravit:backup --keep=3 --keep-files=2')->assertSuccessful();
        }

        $db = collect(File::glob($this->backups.'/db-*.sqlite'))->map(fn (string $f): string => basename($f))->sort()->values();
        $files = collect(File::glob($this->backups.'/files-*.tar.gz'))->map(fn (string $f): string => basename($f))->sort()->values();

        $this->assertSame(['db-20260902-030000.sqlite', 'db-20260903-030000.sqlite', 'db-20260904-030000.sqlite'], $db->all());
        $this->assertSame(['files-20260903-030000.tar.gz', 'files-20260904-030000.tar.gz'], $files->all());
    }

    private function fakeDisk(int $freeMb): void
    {
        $this->app->instance(ServerHealth::class, new class($freeMb) extends ServerHealth
        {
            public function __construct(private readonly int $freeMb) {}

            public function freeDiskMb(string $path): ?int
            {
                return $this->freeMb;
            }

            public function directorySizeBytes(string $path): int
            {
                return 0;
            }
        });
    }
}
