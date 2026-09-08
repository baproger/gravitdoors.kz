<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DealStageChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Deal $deal,
        public ?FactoryStage $from,
        public FactoryStage $to,
        public ?User $actor = null,
    ) {}
}
