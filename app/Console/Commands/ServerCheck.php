<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Permission;
use App\Models\User;
use App\Services\AccessControl;
use App\Support\ServerHealth;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

/**
 * Утренняя проверка сервера: диск, память, свежесть бэкапа.
 *
 * На машине с 1 ГБ памяти и 15 ГБ диска сайт падает не от нагрузки, а от того,
 * что кончилось место под сессии и логи или память под третий PHP-процесс.
 * Заметить это заранее дешевле, чем восстанавливать: команда предупреждает
 * того, кому открыты «Роли и доступы», то есть директора.
 */
class ServerCheck extends Command
{
    protected $signature = 'gravit:server-check
        {--dry : Только показать, без уведомлений}
        {--min-disk-mb= : Порог свободного диска, МБ (по умолчанию из config/gravit.php)}
        {--min-memory-mb= : Порог доступной памяти, МБ (по умолчанию из config/gravit.php)}';

    protected $description = 'Проверяет свободный диск, память и свежесть бэкапа; предупреждает директора';

    private ServerHealth $health;

    /** Зависимость — в handle(), а не в конструкторе: так тесты подменяют диск. */
    public function handle(ServerHealth $health): int
    {
        $this->health = $health;

        $minDisk = (int) ($this->option('min-disk-mb') ?: config('gravit.server.min_free_disk_mb'));
        $minMemory = (int) ($this->option('min-memory-mb') ?: config('gravit.server.min_free_memory_mb'));

        $freeDisk = $this->health->freeDiskMb(base_path());
        $freeMemory = $this->health->availableMemoryMb();
        $backupAge = $this->latestBackupAgeHours();
        $problems = [];

        if ($freeDisk !== null && $freeDisk < $minDisk) {
            $problems[] = "Свободно на диске {$freeDisk} МБ (порог {$minDisk}).";
        }

        if ($freeMemory !== null && $freeMemory < $minMemory) {
            $problems[] = "Доступно памяти {$freeMemory} МБ (порог {$minMemory}).";
        }

        if ($backupAge === null) {
            $problems[] = 'Резервных копий нет: gravit:backup ни разу не отработал.';
        } elseif ($backupAge > 36) {
            $problems[] = "Последняя копия базы сделана {$backupAge} ч назад: ночной бэкап не отработал.";
        }

        $weak = $this->accountsWithWeakPasswords();

        if ($weak !== []) {
            $problems[] = 'Пароль угадывается с первой попытки у: '.implode(', ', $weak).'. Сменить немедленно.';
        }

        $this->table(
            ['Проверка', 'Значение'],
            [
                ['Свободно на диске, МБ', $freeDisk ?? 'неизвестно'],
                ['Доступно памяти, МБ', $freeMemory ?? 'неизвестно'],
                ['Последняя копия базы, ч назад', $backupAge ?? 'нет'],
                ['Учётных записей со слабым паролем', count($weak)],
            ],
        );

        if ($problems === []) {
            $this->info('Сервер в порядке.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->warn($problem);
        }

        if ($this->option('dry')) {
            $this->info('Пробный запуск: уведомления не отправлены.');

            return self::FAILURE;
        }

        Notification::make()
            ->title('Сервер: '.count($problems).' '.(count($problems) === 1 ? 'проблема' : 'проблемы'))
            ->body(implode(' ', $problems))
            ->danger()
            ->persistent()
            ->sendToDatabase($this->recipients());

        return self::FAILURE;
    }

    /**
     * Почты сотрудников, чей пароль подбирается с первой попытки.
     *
     * Появилось, когда репозиторий стал публичным. В README открыто написано,
     * что у демо-учётных записей пароль `password`, — это правильно для
     * разработки, но если такую запись когда-нибудь заведут на бою (перенос
     * базы с разработки, спешная правка руками), директор с паролем из
     * инструкции окажется в открытом доступе вместе с кодом.
     *
     * Проверяется пароль, а не почта: `manager@gravit.kz` на боевом сервере —
     * это, скорее всего, настоящий менеджер, и ругаться на него не за что.
     *
     * Своего пароля директора в списке нет и быть не может: подбор идёт по
     * заведомо мусорным строкам, а репозиторий читают посторонние.
     *
     * Только на бою. На разработке `password` у всех — так задумано сидером,
     * и ругаться на это значит приучить пропускать предупреждение мимо глаз.
     *
     * @return list<string>
     */
    private function accountsWithWeakPasswords(): array
    {
        if (! $this->laravel->environment('production')) {
            return [];
        }

        $guesses = ['password', '12345678', 'qwerty123', 'admin123', 'gravit123'];
        $found = [];

        foreach (User::query()->where('is_active', true)->get() as $user) {
            foreach ($guesses as $guess) {
                if (Hash::check($guess, (string) $user->password)) {
                    $found[] = $user->email;

                    break;
                }
            }
        }

        return $found;
    }

    /** Возраст самой свежей копии базы в часах; null, если копий нет. */
    private function latestBackupAgeHours(): ?int
    {
        $dir = (string) config('gravit.server.backup_dir');

        $latest = collect(File::glob("{$dir}/db-*.sqlite"))
            ->merge(File::glob("{$dir}/db-*.sql.gz"))
            ->map(fn (string $file): int => File::lastModified($file))
            ->max();

        return $latest === null ? null : (int) floor((now()->getTimestamp() - $latest) / 3600);
    }

    /**
     * Кому писать: тем, кому открыты «Роли и доступы» — по матрице это директор.
     *
     * @return Collection<int, User>
     */
    private function recipients(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user): bool => AccessControl::allows($user, Permission::SettingsAccess))
            ->values();
    }
}
