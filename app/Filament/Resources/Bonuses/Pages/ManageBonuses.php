<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bonuses\Pages;

use App\Enums\BonusStatus;
use App\Filament\Resources\Bonuses\BonusResource;
use App\Models\Bonus;
use App\Support\Money;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageBonuses extends ManageRecords
{
    protected static string $resource = BonusResource::class;

    public function getTitle(): string
    {
        return 'Бонусы сотрудников';
    }

    public function getSubheading(): ?string
    {
        return 'Бонус начисляется за месяц; в зарплатную ведомость попадает после утверждения администратором.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Начислить бонус')->modalHeading('Начислить бонус')];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $sum = fn (string $status): string => Money::format((float) Bonus::query()->where('status', $status)->sum('amount'));

        return [
            'pending' => Tab::make('На утверждении')
                ->badge($sum(BonusStatus::Pending->value))
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BonusStatus::Pending->value)),
            'approved' => Tab::make('Утверждены')
                ->badge($sum(BonusStatus::Approved->value))
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BonusStatus::Approved->value)),
            'all' => Tab::make('Все'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return Bonus::query()->where('status', BonusStatus::Pending->value)->exists() ? 'pending' : 'approved';
    }

    /** @return array<string, mixed> */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = BonusStatus::Pending->value;

        return $data;
    }
}
