<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Deal;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Сделка продаж дошла до этапа с triggers_production и породила наряд завода. */
class DealHandedToProduction
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Deal $salesDeal,
        public Deal $productionOrder,
        public ?User $actor = null,
    ) {}
}
