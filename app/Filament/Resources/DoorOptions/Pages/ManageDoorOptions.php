<?php

declare(strict_types=1);

namespace App\Filament\Resources\DoorOptions\Pages;

use App\Filament\Resources\DoorOptions\DoorOptionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDoorOptions extends ManageRecords
{
    protected static string $resource = DoorOptionResource::class;

    public function getTitle(): string
    {
        return 'Прайс конфигуратора';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Новая позиция')];
    }
}
