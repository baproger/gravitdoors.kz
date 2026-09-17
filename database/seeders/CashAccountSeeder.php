<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CashAccountType;
use App\Models\CashAccount;
use Illuminate\Database\Seeder;

/** Два счёта по умолчанию: касса для наличных и банк для карт, Kaspi и переводов. */
class CashAccountSeeder extends Seeder
{
    public function run(): void
    {
        CashAccount::firstOrCreate(['type' => CashAccountType::Cash->value], ['name' => 'Касса', 'opening_balance' => 0, 'opening_at' => now()->startOfYear()]);
        CashAccount::firstOrCreate(['type' => CashAccountType::Bank->value], ['name' => 'Банк — расчётный счёт', 'opening_balance' => 0, 'opening_at' => now()->startOfYear()]);
    }
}
