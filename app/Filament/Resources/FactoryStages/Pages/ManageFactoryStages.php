<?php

declare(strict_types=1);

namespace App\Filament\Resources\FactoryStages\Pages;

use App\Filament\Resources\FactoryStages\FactoryStageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

/**
 * Одна страница вместо связки список/создание/редактирование: этапов немного,
 * и настраивать их удобнее в модальных окнах, не теряя из виду всю воронку.
 */
class ManageFactoryStages extends ManageRecords
{
    protected static string $resource = FactoryStageResource::class;

    public function getTitle(): string
    {
        return 'Этапы воронок';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Новый этап'),
        ];
    }
}
