<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Deal;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Завод закрыл этап с completes_production — сделка продаж стала «Готово к отгрузке». */
class ProductionCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Deal $productionOrder,
        public Deal $salesDeal,
        public ?User $actor = null,
    ) {}
}
