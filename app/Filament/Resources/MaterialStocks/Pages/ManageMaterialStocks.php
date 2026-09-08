<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaterialStocks\Pages;

use App\Filament\Resources\MaterialStocks\MaterialStockResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMaterialStocks extends ManageRecords
{
    protected static string $resource = MaterialStockResource::class;

    public function getTitle(): string
    {
        return 'Склад материалов';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Новый материал')];
    }
}
