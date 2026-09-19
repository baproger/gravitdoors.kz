<?php

declare(strict_types=1);

namespace App\Support\Uploads;

use Illuminate\Support\Facades\Storage;

/**
 * Загруженные файлы лежат на закрытом диске, а не в public/storage.
 *
 * Раньше чек или договор открывался по прямой ссылке без входа в систему:
 * достаточно было знать адрес — а адреса попадают в историю браузера,
 * в пересланные сообщения, в логи. Теперь файл отдаётся только по подписанной
 * ссылке, которая живёт полчаса и выдаётся только тому, кто уже вошёл и
 * открыл карточку. Ещё это закрывает загрузку исполняемого файла в веб-корень.
 */
final class PrivateFiles
{
    /** Диск для всех загрузок: storage/app/private, наружу не смотрит. */
    public const DISK = 'local';

    /** Подписанная ссылка на файл; null, если файла нет. */
    public static function url(?string $path, int $minutes = 30): ?string
    {
        if (blank($path)) {
            return null;
        }

        return Storage::disk(self::DISK)->temporaryUrl($path, now()->addMinutes($minutes));
    }
}
