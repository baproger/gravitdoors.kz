<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tenders\Tables;

use App\Enums\Permission;
use App\Enums\TenderPlatform;
use App\Enums\TenderStatus;
use App\Filament\Resources\Tenders\TenderResource;
use App\Models\Tender;
use App\Services\AccessControl;
use App\Support\Money;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TendersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('deadline_at')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('lots')->withCount('lots'))
            ->recordUrl(fn (Tender $record): string => TenderResource::cardUrl($record))
            ->recordClasses(fn (Tender $record): ?string => $record->isDeadlineSoon() ? 'dl-row--overdue' : null)
            ->columns([
                TextColumn::make('title')
                    ->label('Закупка')
                    ->description(fn (Tender $record): string => collect([
                        filled($record->announcement_number) ? "№ {$record->announcement_number}" : null,
                        $record->customer_name,
                    ])->filter()->implode(' · '))
                    ->searchable(['title', 'announcement_number', 'customer_name', 'customer_bin'])
                    ->weight('semibold')
                    ->wrap(),

                TextColumn::make('status')->label('Статус')->badge(),

                TextColumn::make('deadline_at')
                    ->label('Приём заявок до')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->description(fn (Tender $record): ?string => $record->deadlineHint())
                    ->color(fn (Tender $record): ?string => $record->isDeadlineSoon() ? 'danger' : null)
                    ->sortable(),

                TextColumn::make('lots_count')
                    ->label('Лоты')
                    ->alignCenter(),

                TextColumn::make('bid_total')
                    ->label('Наша заявка')
                    ->state(fn (Tender $record): string => $record->bidTotal() > 0 ? Money::format($record->bidTotal()) : '—')
                    ->alignEnd()
                    ->visible(fn (): bool => AccessControl::can(Permission::KanbanMoney)),

                TextColumn::make('platform')
                    ->label('Площадка')
                    ->badge()
                    ->color('gray')
                    ->visibleFrom('lg')
                    ->toggleable(),

                TextColumn::make('manager.name')
                    ->label('Ответственный')
                    ->placeholder('—')
                    ->visibleFrom('md')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Статус')->options(TenderStatus::class)->multiple(),
                SelectFilter::make('platform')->label('Площадка')->options(TenderPlatform::class),
                SelectFilter::make('manager_id')->label('Ответственный')->relationship('manager', 'name'),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->iconButton()
                    ->authorize(fn (Tender $record): bool => auth()->user()?->can('delete', $record) ?? false)
                    ->visible(fn (Tender $record): bool => $record->canBeDeleted())
                    ->modalDescription('Тендер удалится вместе с лотами и списком документов.'),
            ])
            ->emptyStateHeading('Тендеров нет')
            ->emptyStateDescription('Заведите тендер, когда нашли закупку на площадке: срок подачи, заказчик, лоты и документы.');
    }
}
