<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\ServerHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Резервная копия базы и загруженных файлов.
 *
 * SQLite копируется через собственный `VACUUM INTO` — согласованный снимок
 * даже во время записи. MySQL — через `mysqldump` с одной транзакцией, без
 * блокировки таблиц. Старые копии удаляются, чтобы диск не кончился от
 * самих бэкапов.
 *
 * База и файлы хранятся с разной глубиной. База весит мегабайты, и 14 копий
 * ничего не стоят. Файлы (чеки, договоры) только растут и никогда не удаляются,
 * поэтому каждый архив — это полный вес папки: 14 копий по 1 ГБ на диске
 * в 15 ГБ съели бы всё место за две недели. Файлов хватает трёх копий: их
 * содержимое одинаковое, различаются только добавленные за день документы.
 */
class BackupDatabase extends Command
{
    protected $signature = 'gravit:backup
        {--keep=14 : Сколько последних копий базы хранить}
        {--keep-files=3 : Сколько последних архивов файлов хранить}';

    protected $description = 'Снимок базы и папки storage/app/public с ротацией старых копий';

    private ServerHealth $health;

    /** Зависимость — в handle(), а не в конструкторе: так тесты подменяют диск. */
    public function handle(ServerHealth $health): int
    {
        $this->health = $health;

        $dir = (string) config('gravit.server.backup_dir');
        File::ensureDirectoryExists($dir);

        $stamp = now()->format('Ymd-His');
        // Драйвер соединения, а не его имя: соединение можно назвать как угодно.
        $driver = DB::connection()->getDriverName();

        $dbFile = match ($driver) {
            'sqlite' => $this->dumpSqlite($dir, $stamp),
            'mysql', 'mariadb' => $this->dumpMysql($dir, $stamp),
            default => null,
        };

        if ($dbFile === null) {
            $this->error("База «{$driver}» не поддерживается: нужен sqlite, mysql или mariadb.");

            return self::FAILURE;
        }

        $this->info('База: '.basename($dbFile).' ('.$this->size($dbFile).')');

        $this->archiveFiles($dir, $stamp);

        $this->rotate($dir, ['db-*.sqlite', 'db-*.sql.gz'], (int) $this->option('keep'));
        $this->rotate($dir, ['files-*.tar.gz'], (int) $this->option('keep-files'));

        return self::SUCCESS;
    }

    /**
     * Архив файлов делается только если после него на диске останется запас.
     *
     * Архив сжимается плохо (PDF и фото уже сжаты), поэтому считаем его размер
     * равным размеру папки. Если места впритык, лучше пропустить архив файлов и
     * предупредить, чем забить диск и уронить сайт: база уже сохранена.
     */
    private function archiveFiles(string $dir, string $stamp): void
    {
        $files = storage_path('app/public');

        if (! File::isDirectory($files)) {
            return;
        }

        $needMb = (int) ceil($this->health->directorySizeBytes($files) / 1_048_576);
        $freeMb = $this->health->freeDiskMb($dir);
        $reserveMb = (int) config('gravit.server.min_free_disk_mb', 1500);

        if ($freeMb !== null && $freeMb - $needMb < $reserveMb) {
            $this->warn("Архив файлов пропущен: нужно ~{$needMb} МБ, свободно {$freeMb} МБ, запас {$reserveMb} МБ. Освободите диск.");

            return;
        }

        $archive = "{$dir}/files-{$stamp}.tar.gz";
        $cmd = sprintf('tar -czf %s -C %s .', escapeshellarg($archive), escapeshellarg($files));
        exec($cmd, $output, $code);

        $code === 0
            ? $this->info('Файлы: '.basename($archive).' ('.$this->size($archive).')')
            : $this->warn('Архив файлов не создан (tar вернул '.$code.').');
    }

    private function dumpSqlite(string $dir, string $stamp): string
    {
        $file = "{$dir}/db-{$stamp}.sqlite";
        DB::statement('VACUUM INTO '.DB::getPdo()->quote($file));

        return $file;
    }

    private function dumpMysql(string $dir, string $stamp): ?string
    {
        $file = "{$dir}/db-{$stamp}.sql.gz";
        $connection = config('database.default');
        $c = config("database.connections.{$connection}");

        // Пароль — через переменную окружения, а не в аргументах: аргументы видны в ps.
        $cmd = sprintf(
            'MYSQL_PWD=%s mysqldump --single-transaction --quick --routines --no-tablespaces --set-gtid-purged=OFF -h %s -P %s -u %s %s | gzip > %s',
            escapeshellarg((string) ($c['password'] ?? '')),
            escapeshellarg((string) ($c['host'] ?? '127.0.0.1')),
            escapeshellarg((string) ($c['port'] ?? 3306)),
            escapeshellarg((string) ($c['username'] ?? 'root')),
            escapeshellarg((string) ($c['database'] ?? '')),
            escapeshellarg($file),
        );

        exec($cmd, $output, $code);

        if ($code !== 0 || ! File::exists($file) || File::size($file) < 100) {
            $this->error('mysqldump не отработал (код '.$code.'). Есть ли mysqldump на сервере и доступ у пользователя базы?');
            File::delete($file);

            return null;
        }

        return $file;
    }

    /** @param list<string> $patterns */
    private function rotate(string $dir, array $patterns, int $keep): void
    {
        foreach ($patterns as $pattern) {
            $old = collect(File::glob("{$dir}/{$pattern}"))
                ->sortDesc()
                ->slice(max(1, $keep));

            foreach ($old as $file) {
                File::delete($file);
            }

            if ($old->isNotEmpty()) {
                $this->line('Удалено старых копий: '.$old->count().' ('.$pattern.')');
            }
        }
    }

    private function size(string $path): string
    {
        $bytes = File::size($path);

        return $bytes > 1_048_576
            ? round($bytes / 1_048_576, 1).' МБ'
            : round($bytes / 1024).' КБ';
    }
}
