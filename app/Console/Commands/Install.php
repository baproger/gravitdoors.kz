<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\FactoryStage;
use App\Models\User;
use Database\Seeders\ProductionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Первый запуск на боевом сервере: миграции, справочники, директор.
 *
 * Одна команда вместо трёх шагов из инструкции, каждый из которых можно
 * забыть: без этапов продаж не завести сделку, без директора — не войти.
 * Повторный запуск безопасен: справочники не трогаются, если в базе уже есть
 * этапы (их правит владелец), второй директор не создаётся.
 */
class Install extends Command
{
    protected $signature = 'gravit:install
        {--name= : Имя директора}
        {--email= : Почта директора (логин)}
        {--password= : Пароль директора, не короче 8 символов}';

    protected $description = 'Установка на боевой сервер: миграции, справочники без демо-данных, директор';

    public function handle(): int
    {
        $this->call('migrate', ['--force' => true]);

        if (FactoryStage::query()->exists()) {
            $this->line('Справочники уже есть — не трогаю: этапы и прайс правит владелец в панели.');
        } else {
            $this->call('db:seed', ['--class' => ProductionSeeder::class, '--force' => true]);
            $this->info('Справочники заполнены: этапы обеих воронок, склад, касса и банк, прайс.');
        }

        $admin = User::query()->where('role', UserRole::Admin->value)->first();

        if ($admin) {
            $this->line("Директор уже есть: {$admin->email}.");

            return self::SUCCESS;
        }

        return $this->createDirector() ? self::SUCCESS : self::FAILURE;
    }

    private function createDirector(): bool
    {
        $data = [
            'name' => $this->option('name') ?: ($this->input->isInteractive() ? $this->ask('Имя директора') : null),
            'email' => $this->option('email') ?: ($this->input->isInteractive() ? $this->ask('Почта директора (логин)') : null),
            'password' => $this->option('password') ?: ($this->input->isInteractive() ? $this->secret('Пароль (не короче 8 символов)') : null),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            $this->error('Директор не создан. Передайте --name, --email и --password:');

            foreach ($validator->errors()->all() as $message) {
                $this->line('  '.$message);
            }

            return false;
        }

        User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => UserRole::Admin->value,
            'is_active' => true,
            'hired_at' => now()->toDateString(),
        ]);

        $this->info("Директор создан: {$data['email']}. Вход — /admin.");

        return true;
    }
}
