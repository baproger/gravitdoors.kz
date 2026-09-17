<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Pages;

use App\Enums\ExpenseStatus;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Support\Money;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageExpenses extends ManageRecords
{
    protected static string $resource = ExpenseResource::class;

    public function getTitle(): string
    {
        return 'Расходы';
    }

    public function getSubheading(): ?string
    {
        return 'Менеджер вносит, администратор подтверждает. В финансовую сводку попадают только подтверждённые.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Новый расход')->modalHeading('Новый расход')];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $sum = fn (string $status): string => Money::format((float) Expense::query()->where('status', $status)->sum('amount'));

        return [
            'pending' => Tab::make('На проверке')
                ->badge($sum(ExpenseStatus::Pending->value))
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ExpenseStatus::Pending->value)),
            'approved' => Tab::make('Оплачено')
                ->badge($sum(ExpenseStatus::Approved->value))
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ExpenseStatus::Approved->value)),
            'all' => Tab::make('Все')
                ->badge((string) Expense::query()->count()),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return Expense::query()->pending()->exists() ? 'pending' : 'approved';
    }

    /** @return array<string, mixed> */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = ExpenseStatus::Pending->value;

        return $data;
    }
}
