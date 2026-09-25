<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tenders\Pages;

use App\Filament\Resources\Tenders\TenderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTender extends CreateRecord
{
    protected static string $resource = TenderResource::class;

    /** Лоты заводятся на карточке тендера — сразу туда. */
    protected function getRedirectUrl(): string
    {
        return TenderResource::getUrl('edit', ['record' => $this->record]);
    }
}
