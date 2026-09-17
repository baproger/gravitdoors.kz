<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\CashLedger;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@gravit.kz'],
            [
                'name' => 'Администратор Gravit',
                'password' => Hash::make('password'),
                'role' => UserRole::Admin->value,
                'phone' => '+7 (701) 000-00-01',
                'salary' => 400_000,
                'hired_at' => now()->subYears(3),
                'birth_date' => '1985-05-30',
            ],
        );

        $this->call([
            FactoryStageSeeder::class,
            MaterialStockSeeder::class,
            CashAccountSeeder::class,
            DoorOptionSeeder::class,
            DemoDataSeeder::class,
        ]);

        // Демо-платежи созданы — разнести их по кассе и банку.
        app(CashLedger::class)->backfill();
    }
}
