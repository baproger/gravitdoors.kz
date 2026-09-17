<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CashLedger;
use Illuminate\Console\Command;

/** Разнести старые платежи и расходы по кассе и банку. Повторный запуск ничего не задваивает. */
class FinanceBackfill extends Command
{
    protected $signature = 'gravit:finance-backfill';

    protected $description = 'Создаёт движения по счетам для платежей и подтверждённых расходов без движения';

    public function handle(CashLedger $ledger): int
    {
        $result = $ledger->backfill();

        $this->info("Платежей разнесено: {$result['payments']}, расходов: {$result['expenses']}.");

        return self::SUCCESS;
    }
}
