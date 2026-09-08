<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    public function getTitle(): string
    {
        return 'Сотрудники';
    }

    /**
     * ListRecords по умолчанию не проверяет права — он полагается на доступ ко
     * всему ресурсу. Но ресурс мы открыли всем, чтобы сотрудник видел свою
     * карточку, поэтому список закрываем здесь явно.
     */
    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Новый сотрудник')];
    }
}
