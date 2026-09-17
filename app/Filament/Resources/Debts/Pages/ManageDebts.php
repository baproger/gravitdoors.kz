<?php

declare(strict_types=1);

namespace App\Filament\Resources\Debts\Pages;

use App\Enums\DebtStatus;
use App\Filament\Resources\Debts\DebtResource;
use App\Models\Debt;
use App\Support\Money;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageDebts extends ManageRecords
{
    protected static string $resource = DebtResource::class;

    public function getTitle(): string
    {
        return 'Задолженности — мы должны';
    }

    public function getSubheading(): ?string
    {
        return 'Платёж по долгу создаёт подтверждённый расход и списание со счёта. Долг с остатком нельзя закрыть руками.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Новый долг')->modalHeading('Новый долг')];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $open = fn (): Builder => Debt::query()->where('status', DebtStatus::Open->value);
        $remaining = fn (Builder $q): string => Money::format((float) $q->selectRaw('SUM(amount - paid_amount) as due')->value('due'));

        return [
            'open' => Tab::make('Открытые')
                ->badge($remaining($open()))
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', DebtStatus::Open->value)),
            'overdue' => Tab::make('Просроченные')
                ->badge($remaining($open()->whereDate('due_at', '<', today())))
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', DebtStatus::Open->value)->whereDate('due_at', '<', today())),
            'paid' => Tab::make('Погашенные')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', DebtStatus::Paid->value)),
            'all' => Tab::make('Все'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'open';
    }
}
