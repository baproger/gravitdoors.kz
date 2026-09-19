<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * Что сейчас с диском и памятью на сервере.
 *
 * Отдельный класс, а не вызовы `disk_free_space()` по месту: в тестах его
 * подменяют, чтобы проверить сценарии «диск кончается» без реального диска.
 * Память читается из /proc/meminfo — на macOS и в контейнерах без него метод
 * честно отвечает null, и проверка памяти просто пропускается.
 */
class ServerHealth
{
    /** Свободное место на диске, где лежит путь, в мегабайтах. */
    public function freeDiskMb(string $path): ?int
    {
        $bytes = @disk_free_space($path);

        return $bytes === false ? null : (int) floor($bytes / 1_048_576);
    }

    /** Доступная память (MemAvailable) в мегабайтах; null, если её не узнать. */
    public function availableMemoryMb(): ?int
    {
        if (! is_readable('/proc/meminfo')) {
            return null;
        }

        $info = (string) file_get_contents('/proc/meminfo');

        if (preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', $info, $m) !== 1) {
            return null;
        }

        return (int) floor(((int) $m[1]) / 1024);
    }

    /** Сколько занимает папка со всем содержимым, в байтах. */
    public function directorySizeBytes(string $path): int
    {
        if (! File::isDirectory($path)) {
            return 0;
        }

        $total = 0;

        foreach (File::allFiles($path, hidden: true) as $file) {
            $total += $file->getSize();
        }

        return $total;
    }
}
