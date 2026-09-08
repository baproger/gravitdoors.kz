<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Pages;

use App\Filament\Resources\Deals\DealResource;
use App\Services\DoorProductionService;
use Filament\Resources\Pages\CreateRecord;

class CreateDeal extends CreateRecord
{
    protected static string $resource = DealResource::class;

    /**
     * Цена — производная от спецификации, а не поле формы: считаем её сервисом
     * сразу после создания, чтобы в базе не оседали суммы, набранные вручную.
     */
    protected function afterCreate(): void
    {
        app(DoorProductionService::class)->syncPricing($this->record);
    }
}
