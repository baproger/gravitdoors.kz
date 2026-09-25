<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tenders\Pages;

use App\Filament\Resources\Tenders\TenderResource;
use App\Models\Tender;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Livewire\Attributes\On;

class EditTender extends EditRecord
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
        /** @var Tender $tender */
        $tender = $this->record;

        return [
            DeleteAction::make()
                ->authorize(fn (): bool => auth()->user()->can('delete', $tender) && $tender->canBeDeleted())
                ->modalDescription('Тендер удалится вместе с лотами и списком документов.'),
        ];
    }

    /**
     * Статус тендера меняется от итогов лотов. Обновляется только это поле:
     * перечитать форму целиком значило бы стереть несохранённые правки карточки.
     */
    #[On('tender-lots-changed')]
    public function syncStatusField(): void
    {
        /** @var Tender $tender */
        $tender = $this->record->refresh();

        $this->data['status'] = $tender->status->value;
    }
}
