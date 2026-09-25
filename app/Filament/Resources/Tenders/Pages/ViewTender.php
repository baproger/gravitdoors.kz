<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tenders\Pages;

use App\Filament\Resources\Tenders\TenderResource;
use App\Models\Tender;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/** Просмотр — для тех, у кого право на тендеры «Чтение» (бухгалтер смотрит обеспечение). */
class ViewTender extends ViewRecord
{
    protected static string $resource = TenderResource::class;

    public function getTitle(): string
    {
        /** @var Tender $tender */
        $tender = $this->record;

        return $tender->displayName();
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
