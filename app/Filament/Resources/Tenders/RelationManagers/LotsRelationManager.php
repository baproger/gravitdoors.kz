<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tenders\RelationManagers;

use App\Enums\DoorCategory;
use App\Enums\DoorModel;
use App\Enums\Permission;
use App\Enums\TenderLotResult;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Tender;
use App\Models\TenderLot;
use App\Services\AccessControl;
use App\Services\TenderService;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/**
 * Лоты тендера: что поставляем, сколько, почём — и итог по каждому.
 *
 * Характеристики двери здесь не выбираются: в заявке важны количество и цена.
 * Цена по прайсу и себестоимость считаются по позициям «по умолчанию», чтобы
 * до подачи было видно, какая цена ещё в плюсе.
 */
class LotsRelationManager extends RelationManager
{
    protected static string $relationship = 'lots';

    protected static bool $isLazy = false;

    protected static ?string $title = 'Лоты';

    protected static \BackedEnum|string|null $icon = 'heroicon-o-queue-list';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Лот')
                ->columns(3)
                ->schema([
                    TextInput::make('lot_number')
                        ->label('№ лота')
                        ->placeholder('1')
                        ->maxLength(64),

                    TextInput::make('name')
                        ->label('Наименование в закупке')
                        ->placeholder('Дверь металлическая входная 2050×950')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(2),

                    Select::make('category')
                        ->label('Линейка')
                        ->options(DoorCategory::class)
                        ->default(DoorCategory::Comfort->value)
                        ->required()
                        ->native(false)
                        ->disabled(fn (?TenderLot $record): bool => $record?->hasDeal() ?? false),

                    Select::make('model')
                        ->label('Модель')
                        ->options(DoorModel::class)
                        ->required()
                        ->native(false)
                        ->disabled(fn (?TenderLot $record): bool => $record?->hasDeal() ?? false),

                    TextInput::make('quantity')
                        ->label('Количество, шт')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(TenderLot::MAX_QUANTITY)
                        ->default(1)
                        ->required()
                        ->disabled(fn (?TenderLot $record): bool => $record?->hasDeal() ?? false),

                    TextInput::make('height')
                        ->label('Высота, мм')
                        ->numeric()
                        ->integer()
                        ->default(config('gravit.pricing.default_height'))
                        ->minValue(config('gravit.pricing.min_height'))
                        ->maxValue(config('gravit.pricing.max_height'))
                        ->required()
                        ->disabled(fn (?TenderLot $record): bool => $record?->hasDeal() ?? false),

                    TextInput::make('width')
                        ->label('Ширина, мм')
                        ->numeric()
                        ->integer()
                        ->default(config('gravit.pricing.default_width'))
                        ->minValue(config('gravit.pricing.min_width'))
                        ->maxValue(config('gravit.pricing.max_width'))
                        ->required()
                        ->disabled(fn (?TenderLot $record): bool => $record?->hasDeal() ?? false),
                ]),

            Section::make('Цена за единицу')
                ->description('По цене заказчика и нашей считаются суммы лота и маржа')
                ->columns(2)
                ->schema([
                    TextInput::make('budget_unit_price')
                        ->label('Цена заказчика (потолок)')
                        ->numeric()
                        ->minValue(0)
                        ->suffix(config('gravit.currency.symbol'))
                        ->live(onBlur: true),

                    TextInput::make('bid_unit_price')
                        ->label('Наша цена')
                        ->numeric()
                        ->minValue(0)
                        ->suffix(config('gravit.currency.symbol'))
                        ->helperText(fn (?TenderLot $record): ?string => $record?->hasDeal()
                            ? 'Цена зафиксирована в сделке '.$record->deal?->number
                            : ($record?->list_unit_price !== null ? 'По прайсу: '.Money::format((float) $record->list_unit_price) : null))
                        ->rule(static fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            $budget = (float) $get('budget_unit_price');

                            if ($budget > 0 && (float) $value > $budget) {
                                $fail('Наша цена выше цены заказчика — такую заявку отклонят.');
                            }
                        })
                        ->disabled(fn (?TenderLot $record): bool => $record?->hasDeal() ?? false),
                ]),

            Section::make('Итог')
                ->columns(3)
                ->schema([
                    Select::make('result')
                        ->label('Итог по лоту')
                        ->options(TenderLotResult::class)
                        ->default(TenderLotResult::Pending->value)
                        ->required()
                        ->live()
                        ->native(false)
                        ->disabled(fn (?TenderLot $record): bool => $record?->hasDeal() ?? false),

                    TextInput::make('winner_name')
                        ->label('Кто выиграл')
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => self::isLost($get('result'))),

                    TextInput::make('winner_unit_price')
                        ->label('Цена победителя за ед.')
                        ->numeric()
                        ->minValue(0)
                        ->suffix(config('gravit.currency.symbol'))
                        ->visible(fn (Get $get): bool => self::isLost($get('result'))),

                    Textarea::make('comment')
                        ->label('Комментарий')
                        ->helperText('Уйдёт в позицию сделки как комментарий для цеха')
                        ->rows(2)
                        ->maxLength(500)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        $money = fn (): bool => AccessControl::can(Permission::KanbanMoney);

        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn ($query) => $query->with('deal'))
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label('Лот')
                    ->state(fn (TenderLot $record): string => $record->displayName())
                    ->description(fn (TenderLot $record): string => trim($record->category->getLabel().' '.$record->model->getLabel())
                        ." · {$record->height}×{$record->width} мм")
                    ->weight('semibold')
                    ->wrap(),

                TextColumn::make('quantity')
                    ->label('Кол-во')
                    ->suffix(' шт')
                    ->alignEnd(),

                TextColumn::make('budget_unit_price')
                    ->label('Заказчик, за ед.')
                    ->state(fn (TenderLot $r): string => $r->budget_unit_price !== null ? Money::format((float) $r->budget_unit_price) : '—')
                    ->description(fn (TenderLot $r): ?string => $r->budget_unit_price !== null ? 'лот '.Money::format($r->budgetTotal()) : null)
                    ->alignEnd()
                    ->visible($money),

                TextColumn::make('bid_unit_price')
                    ->label('Наша, за ед.')
                    ->state(fn (TenderLot $r): string => $r->bid_unit_price !== null ? Money::format((float) $r->bid_unit_price) : '—')
                    ->description(fn (TenderLot $r): ?string => $r->bid_unit_price !== null ? 'лот '.Money::format($r->bidTotal()) : null)
                    ->color(fn (TenderLot $r): ?string => $r->isOverBudget() ? 'danger' : null)
                    ->weight('semibold')
                    ->alignEnd()
                    ->visible($money),

                TextColumn::make('list_unit_price')
                    ->label('По прайсу')
                    ->state(fn (TenderLot $r): string => $r->list_unit_price !== null ? Money::format((float) $r->list_unit_price) : '—')
                    ->alignEnd()
                    ->visible($money)
                    ->toggleable(),

                TextColumn::make('margin')
                    ->label('Маржа')
                    ->state(fn (TenderLot $r): string => $r->marginPercent() !== null ? $r->marginPercent().' %' : '—')
                    ->description(fn (TenderLot $r): ?string => $r->estimated_cost !== null ? 'себест. '.Money::format((float) $r->estimated_cost) : null)
                    ->color(fn (TenderLot $r): ?string => match (true) {
                        $r->marginPercent() === null => null,
                        $r->marginPercent() < 0 => 'danger',
                        $r->marginPercent() < 15 => 'warning',
                        default => 'success',
                    })
                    ->alignEnd()
                    ->visible($money),

                TextColumn::make('result')
                    ->label('Итог')
                    ->badge()
                    ->description(fn (TenderLot $r): ?string => $r->result === TenderLotResult::Lost && filled($r->winner_name)
                        ? $r->winner_name.($r->winner_unit_price !== null ? ' · '.Money::format((float) $r->winner_unit_price) : '')
                        : null),

                TextColumn::make('deal.number')
                    ->label('Сделка')
                    ->placeholder('—')
                    ->color('primary')
                    ->url(fn (TenderLot $r): ?string => $r->deal && auth()->user()?->can('view', $r->deal)
                        ? DealResource::cardUrl($r->deal)
                        : null),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Добавить лот')
                    ->icon('heroicon-m-plus')
                    ->modalHeading('Новый лот')
                    ->after(fn () => $this->syncTender()),
            ])
            ->recordActions([
                Action::make('createDeal')
                    ->label('Создать сделку')
                    ->icon('heroicon-o-briefcase')
                    ->color('success')
                    ->button()
                    ->visible(fn (TenderLot $r): bool => $r->result === TenderLotResult::Won && ! $r->hasDeal())
                    ->authorize(fn (TenderLot $r): bool => auth()->user()?->can('createDeal', [$this->tender(), $r]) ?? false)
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-briefcase')
                    ->modalHeading(fn (TenderLot $r): string => 'Сделка из лота: '.$r->displayName())
                    ->modalDescription(fn (TenderLot $r): string => "В воронку продаж встанет сделка на {$r->quantity} шт по цене тендера "
                        .Money::format($r->bidTotal()).'. Реквизиты заказчика возьмутся из тендера.')
                    ->modalSubmitActionLabel('Создать сделку')
                    ->action(function (TenderLot $r, TenderService $service): void {
                        try {
                            $deal = $service->createDeal($r, auth()->user());
                        } catch (ValidationException $e) {
                            Notification::make()->danger()
                                ->title('Сделка не создана')
                                ->body(collect($e->errors())->flatten()->implode(' '))
                                ->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title("Сделка {$deal->number} создана")
                            ->body('Спецификацию дверей можно уточнить в карточке сделки: сумма останется по тендеру.')
                            ->actions([
                                Action::make('open')->label('Открыть сделку')->url(DealResource::cardUrl($deal)),
                            ])
                            ->send();
                    }),

                Action::make('won')
                    ->label('Выиграли')
                    ->icon('heroicon-o-trophy')
                    ->color('success')
                    ->iconButton()
                    ->tooltip('Отметить: лот выигран')
                    ->visible(fn (TenderLot $r): bool => $r->result === TenderLotResult::Pending)
                    ->authorize(fn (): bool => auth()->user()?->can('update', $this->tender()) ?? false)
                    ->action(function (TenderLot $r): void {
                        $r->update(['result' => TenderLotResult::Won]);
                        $this->syncTender();
                        Notification::make()->success()->title('Лот выигран')->body('Теперь из него можно создать сделку.')->send();
                    }),

                Action::make('lost')
                    ->label('Проиграли')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->iconButton()
                    ->tooltip('Отметить: лот проигран')
                    ->visible(fn (TenderLot $r): bool => $r->result === TenderLotResult::Pending)
                    ->authorize(fn (): bool => auth()->user()?->can('update', $this->tender()) ?? false)
                    ->modalHeading('Лот проигран')
                    ->modalDescription('Кто выиграл и по какой цене — чтобы через полгода было видно, где мы дороже.')
                    ->schema([
                        TextInput::make('winner_name')->label('Кто выиграл')->maxLength(255),
                        TextInput::make('winner_unit_price')->label('Цена победителя за ед.')->numeric()->minValue(0)->suffix(config('gravit.currency.symbol')),
                    ])
                    ->action(function (TenderLot $r, array $data): void {
                        $r->update([
                            'result' => TenderLotResult::Lost,
                            'winner_name' => $data['winner_name'] ?? null,
                            'winner_unit_price' => filled($data['winner_unit_price'] ?? null) ? $data['winner_unit_price'] : null,
                        ]);
                        $this->syncTender();
                    }),

                EditAction::make()
                    ->iconButton()
                    ->modalHeading(fn (TenderLot $r): string => $r->displayName())
                    ->after(fn () => $this->syncTender()),

                DeleteAction::make()
                    ->iconButton()
                    ->visible(fn (TenderLot $r): bool => ! $r->hasDeal())
                    ->after(fn () => $this->syncTender()),
            ])
            ->emptyStateHeading('Лотов пока нет')
            ->emptyStateDescription('Добавьте лоты из техспецификации: изделие, размер, количество и цену заказчика.');
    }

    private function tender(): Tender
    {
        /** @var Tender $tender */
        $tender = $this->getOwnerRecord();

        return $tender;
    }

    /** Итог тендера по лотам — и поле статуса на карточке, чтобы оно не отставало. */
    private function syncTender(): void
    {
        app(TenderService::class)->syncStatus($this->tender());

        $this->dispatch('tender-lots-changed');
    }

    private static function isLost(mixed $state): bool
    {
        $result = $state instanceof TenderLotResult ? $state : TenderLotResult::tryFrom((string) $state);

        return $result === TenderLotResult::Lost;
    }
}
