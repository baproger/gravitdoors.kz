<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Enums\AccessLevel;
use App\Enums\DealEventType;
use App\Enums\Department;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\PipelineType;
use App\Exceptions\ProductionException;
use App\Models\CashAccount;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\DealPayment;
use App\Models\FactoryStage;
use App\Models\MaterialStock;
use App\Models\StockMovement;
use App\Services\AccessControl;
use App\Services\DoorProductionService;
use App\Services\QrCodeService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Все действия по сделке в одном месте.
 *
 * Карточка сделки, список сделок и «Счета» показывают одни и те же кнопки:
 * определённые здесь один раз, они одинаково проверяют права и одинаково
 * ведут себя. Раньше «Принять оплату» жила только на странице «Счета», и
 * менеджер в карточке не понимал, как закрыть оплату, чтобы завершить сделку.
 *
 * В шапке карточки кнопки сгруппированы по отделам (`headerGroups()`): каждый
 * видит свою группу, пустые группы Filament прячет сам.
 */
final class DealActions
{
    /** Кто принимает оплату: финансы всегда, менеджер — по своей сделке, если видит суммы. */
    public static function canAcceptPayment(Deal $deal): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if (AccessControl::can(Permission::FinanceIncomes, AccessLevel::Full)) {
            return true;
        }

        return AccessControl::can(Permission::KanbanMoney) && $user->can('update', $deal);
    }

    /**
     * Шапка карточки: «Принять оплату» отдельно, остальное по отделам.
     *
     * @return list<Action|ActionGroup>
     */
    public static function headerGroups(): array
    {
        return [
            self::pay(),

            self::group(Department::Sales, [self::handOff(), self::recalculate(), self::cancelDeal()]),
            self::group(Department::Finance, [self::blockShipment(), self::remind()]),
            self::group(Department::Factory, [self::completeStage(), self::factoryMaterials(), self::cancelOrder()]),
            self::group(Department::System, [self::track(), self::qr()])->label('Клиент')->icon('heroicon-o-qr-code'),
        ];
    }

    /**
     * Список сделок: те же действия одним меню.
     *
     * @return list<Action>
     */
    public static function forTable(): array
    {
        return [
            self::pay(), self::handOff(), self::blockShipment(), self::remind(),
            self::factoryMaterials(), self::completeStage(), self::cancelOrder(),
            self::cancelDeal(), self::recalculate(), self::track(),
        ];
    }

    /**
     * «Принять оплату»: сумма подставляется как остаток — при первом платеже
     * это вся сумма договора. Чек обязателен: правило сервера, не формы.
     */
    public static function pay(): Action
    {
        return Action::make('pay')
            ->label('Принять оплату')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->modalHeading(fn (Deal $record): string => "Оплата по {$record->number}")
            ->modalDescription(fn (Deal $record): string => sprintf(
                'Сумма сделки %s · оплачено %s · остаток %s. Сумма ниже подставлена как остаток — поправьте, если клиент платит частями. Без чека платёж не принимается.',
                Money::format((float) $record->total_price),
                Money::format((float) $record->prepayment),
                Money::format($record->remainingPayment()),
            ))
            ->modalSubmitActionLabel('Принять оплату')
            ->modalWidth('lg')
            ->authorize(fn (Deal $record): bool => self::canAcceptPayment($record))
            ->visible(fn (Deal $record): bool => ! $record->isFactoryOrder()
                && $record->remainingPayment() > 0
                && ! $record->status_id->isClosed())
            ->schema([
                TextInput::make('amount')
                    ->label('Сумма')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(fn (Deal $record): float => $record->remainingPayment())
                    ->required()
                    ->default(fn (Deal $record): float => $record->remainingPayment())
                    ->helperText(fn (Deal $record): string => 'Остаток к оплате: '.Money::format($record->remainingPayment()))
                    ->suffix(config('gravit.currency.symbol')),

                Select::make('method')
                    ->label('Способ')
                    ->options(PaymentMethod::class)
                    ->default(PaymentMethod::Kaspi->value)
                    ->required()
                    ->native(false),

                Select::make('account_id')
                    ->label('Счёт')
                    ->helperText('Пусто — по способу: наличные в кассу, остальное в банк')
                    ->options(fn (): array => CashAccount::query()->where('is_active', true)->orderBy('id')->pluck('name', 'id')->all())
                    ->visible(fn (): bool => CashAccount::query()->where('is_active', true)->count() > 2)
                    ->native(false),

                DatePicker::make('paid_at')
                    ->label('Дата оплаты')
                    ->default(now())
                    ->maxDate(now())
                    ->displayFormat('d.m.Y')
                    ->required(),

                TextInput::make('comment')
                    ->label('Комментарий')
                    ->placeholder('№ операции, кто платил')
                    ->maxLength(255),

                FileUpload::make('receipt_path')
                    ->label('Чек')
                    ->helperText('Фото или PDF чека, до 10 МБ')
                    ->required()
                    ->directory('receipts')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                    ->maxSize(10240)
                    ->columnSpanFull(),
            ])
            ->action(function (Deal $record, array $data, $livewire): void {
                try {
                    DealPayment::create([
                        'deal_id' => $record->id,
                        'amount' => (float) $data['amount'],
                        'method' => $data['method'],
                        'account_id' => $data['account_id'] ?? null,
                        'paid_at' => $data['paid_at'],
                        'comment' => $data['comment'] ?? null,
                        'receipt_path' => $data['receipt_path'],
                    ]);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Оплата не принята')
                        ->body(collect($e->errors())->flatten()->implode(' '))->send();

                    return;
                }

                $record->refresh();
                self::refreshCard($livewire);

                Notification::make()->success()
                    ->title($record->isPaidInFull() ? 'Сделка оплачена полностью' : 'Оплата принята')
                    ->body($record->isPaidInFull()
                        ? 'Теперь сделку можно перевести на завершающий этап.'
                        : 'Остаток: '.Money::format($record->remainingPayment()))
                    ->send();
            });
    }

    public static function remind(): Action
    {
        return Action::make('remind')
            ->label('Напомнить об оплате')
            ->icon('heroicon-o-bell-alert')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Отметить напоминание об оплате?')
            ->modalDescription('В историю сделки ляжет запись «напомнили об оплате» с датой и вашим именем. Отправка сообщения клиенту — следующий шаг плана.')
            ->authorize(fn (): bool => AccessControl::can(Permission::FinanceInvoices, AccessLevel::Full))
            ->visible(fn (Deal $record): bool => ! $record->isFactoryOrder() && $record->remainingPayment() > 0 && ! $record->status_id->isClosed())
            ->action(function (Deal $record): void {
                DealEvent::record($record, DealEventType::PaymentReminder,
                    'Напомнили клиенту об оплате: остаток '.Money::format($record->remainingPayment()), auth()->user());

                Notification::make()->success()->title('Напоминание записано в историю')->send();
            });
    }

    public static function handOff(): Action
    {
        return Action::make('handOff')
            ->label('Передать в производство')
            ->icon('heroicon-o-arrow-right-circle')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Передать сделку на завод?')
            ->modalDescription('Будет создан производственный наряд, а материалы спецификации спишутся со склада.')
            ->authorize(fn (Deal $record): bool => auth()->user()?->can('move', $record) ?? false)
            ->visible(fn (Deal $record): bool => ! $record->isFactoryOrder()
                && ! $record->status_id->isClosed()
                && $record->activeProductionOrder() === null
                && ! $record->hasCompletedProductionOrder())
            ->action(function (Deal $record, DoorProductionService $production, $livewire): void {
                // Через moveToStage, а не напрямую: иначе кнопка обходила бы
                // регламент этапов, который проверяется при переходе.
                $stage = FactoryStage::query()
                    ->ofPipeline(PipelineType::Sales)
                    ->active()
                    ->where('triggers_production', true)
                    ->ordered()
                    ->first();

                if (! $stage) {
                    Notification::make()->danger()->title('Этап передачи в производство не настроен')->send();

                    return;
                }

                try {
                    $production->moveToStage($record, $stage, auth()->user());
                    $order = $record->refresh()->productionOrder;
                    self::refreshCard($livewire);

                    Notification::make()->success()
                        ->title('Наряд создан')
                        ->body("Производственный наряд {$order?->number} принят цехом.")
                        ->send();
                } catch (ProductionException $e) {
                    Notification::make()->danger()
                        ->title('Не удалось передать в цех')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();
                }
            });
    }

    public static function blockShipment(): Action
    {
        return Action::make('blockShipment')
            ->label(fn (Deal $record): string => $record->isShipmentBlocked() ? 'Снять блокировку отгрузки' : 'Заблокировать отгрузку')
            ->icon(fn (Deal $record): string => $record->isShipmentBlocked() ? 'heroicon-o-lock-open' : 'heroicon-o-lock-closed')
            ->color(fn (Deal $record): string => $record->isShipmentBlocked() ? 'success' : 'danger')
            ->modalHeading(fn (Deal $record): string => $record->isShipmentBlocked()
                ? "Снять блокировку с {$record->number}?"
                : "Придержать отгрузку {$record->number}?")
            ->modalDescription(fn (Deal $record): string => $record->isShipmentBlocked()
                ? 'Сделка снова пойдёт по воронке до закрытия.'
                : 'Цех продолжит работу, но дальше «Передано в производство» сделка не пойдёт, пока блокировка стоит.')
            ->schema(fn (Deal $record): array => $record->isShipmentBlocked() ? [] : [
                Textarea::make('reason')
                    ->label('Причина')
                    ->placeholder('Долг 320 000 ₸ по договору')
                    ->required()
                    ->rows(2),
            ])
            ->authorize(fn (Deal $record): bool => auth()->user()?->can('flagPayment', $record) ?? false)
            ->visible(fn (Deal $record): bool => ! $record->isFactoryOrder() && ! $record->status_id->isClosed())
            ->action(function (Deal $record, array $data, DoorProductionService $production, $livewire): void {
                try {
                    $blocked = ! $record->isShipmentBlocked();
                    $production->setShipmentBlock($record, $blocked, $data['reason'] ?? null, auth()->user());
                    self::refreshCard($livewire);

                    Notification::make()->success()
                        ->title($blocked ? 'Отгрузка заблокирована' : 'Блокировка снята')
                        ->send();
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Не сохранено')->body(collect($e->errors())->flatten()->implode(' '))->send();
                }
            });
    }

    public static function factoryMaterials(): Action
    {
        return Action::make('factoryMaterials')
            ->label('Факт материалов')
            ->icon('heroicon-o-archive-box-arrow-down')
            ->color('gray')
            ->modalHeading(fn (Deal $record): string => "Фактический расход по наряду {$record->number}")
            ->modalDescription('Списание сверх плана или возврат неиспользованного. Плановый расход уже списан при передаче в цех.')
            ->schema([
                Select::make('material_stock_id')
                    ->label('Материал')
                    ->options(fn (): array => MaterialStock::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required()
                    ->native(false),
                Select::make('direction')
                    ->label('Что произошло')
                    ->options([
                        StockMovement::TYPE_OUT => 'Ушло больше плана — списать',
                        StockMovement::TYPE_IN => 'Осталось — вернуть на склад',
                    ])
                    ->default(StockMovement::TYPE_OUT)
                    ->required()
                    ->native(false),
                TextInput::make('quantity')->label('Количество')->numeric()->minValue(0.001)->required(),
                TextInput::make('comment')->label('Комментарий')->maxLength(255),
            ])
            ->authorize(fn (): bool => AccessControl::can(Permission::FactoryMaterials, AccessLevel::Full))
            ->visible(fn (Deal $record): bool => $record->isFactoryOrder() && ! $record->status_id->isClosed())
            ->action(function (Deal $record, array $data, DoorProductionService $production): void {
                try {
                    $production->adjustMaterials(
                        $record,
                        (int) $data['material_stock_id'],
                        (float) $data['quantity'],
                        (string) $data['direction'],
                        $data['comment'] ?? null,
                        auth()->user(),
                    );

                    Notification::make()->success()->title('Расход отмечен')->send();
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Не записано')->body(collect($e->errors())->flatten()->implode(' '))->send();
                }
            });
    }

    /**
     * Закрыть текущий этап наряда. На последнем этапе цеха это же действие
     * закрывает наряд и возвращает сделку продажам — как «Готово ✓» на планшете.
     */
    public static function completeStage(): Action
    {
        return Action::make('completeStage')
            ->label(fn (Deal $record): string => self::finishesOrder($record) ? 'Готово — закрыть наряд' : 'Завершить этап цеха')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-check-badge')
            ->modalIconColor('success')
            ->modalHeading(fn (Deal $record): string => 'Этап «'.($record->currentStage->name ?? '—').'» выполнен?')
            ->modalDescription(fn (Deal $record): string => self::finishesOrder($record)
                ? 'Наряд '.$record->number.' закроется, сдельная оплата за этап запишется на вас'
                    .($record->parentDeal ? ', а сделка '.$record->parentDeal->number.' перейдёт в «Готово к отгрузке».' : '.')
                : 'Этап закроется, оплата запишется на исполнителя, наряд перейдёт на следующий этап.')
            ->modalSubmitActionLabel(fn (Deal $record): string => self::finishesOrder($record) ? 'Да, закрыть наряд' : 'Да, этап выполнен')
            ->modalCancelActionLabel('Отмена')
            // Видимость — не защита: право проверяется и на сервере, иначе рабочий
            // закрывал бы этапы из списка, хотя политика ему это запрещает.
            ->authorize(fn (Deal $record): bool => auth()->user()?->can('move', $record) ?? false)
            ->visible(fn (Deal $record): bool => $record->isFactoryOrder() && ! $record->status_id->isClosed())
            ->action(function (Deal $record, DoorProductionService $production, $livewire): void {
                try {
                    $stage = $record->currentStage?->name;
                    $production->completeCurrentStage($record, auth()->user());
                    $record->refresh();
                    self::refreshCard($livewire);

                    if ($record->status_id->isClosed()) {
                        Notification::make()->success()
                            ->title("Наряд завершён: «{$stage}» закрыт")
                            ->body($record->parentDeal ? "Сделка {$record->parentDeal->number} переведена в «Готово к отгрузке»." : null)
                            ->send();

                        return;
                    }

                    Notification::make()->success()
                        ->title("Этап «{$stage}» закрыт")
                        ->body('Наряд перешёл на «'.($record->currentStage->name ?? '—').'».')
                        ->send();
                } catch (ProductionException $e) {
                    Notification::make()->danger()->title('Этап не закрыт')->body($e->getMessage())->send();
                }
            });
    }

    public static function cancelOrder(): Action
    {
        return Action::make('cancelOrder')
            ->label('Отменить наряд')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Отменить производственный наряд?')
            ->modalDescription('Списанные материалы вернутся на склад, сделка клиента вернётся в работу.')
            ->schema([
                Textarea::make('reason')->label('Причина отмены')->required()->rows(2),
            ])
            ->authorize(fn (Deal $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->visible(fn (Deal $record): bool => $record->isFactoryOrder() && ! $record->status_id->isClosed())
            ->action(function (Deal $record, array $data, DoorProductionService $production, $livewire): void {
                try {
                    $production->cancelProduction($record, auth()->user(), $data['reason']);
                    self::refreshCard($livewire);

                    Notification::make()->success()
                        ->title("Наряд {$record->number} отменён")
                        ->body('Материалы возвращены на склад.')
                        ->send();
                } catch (ProductionException $e) {
                    Notification::make()->danger()->title('Ошибка')->body($e->getMessage())->send();
                }
            });
    }

    public static function cancelDeal(): Action
    {
        return Action::make('cancelDeal')
            ->label('Отменить сделку')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Отменить сделку?')
            ->modalDescription(fn (Deal $record): string => $record->activeProductionOrder()
                ? 'Наряд в цеху будет отменён, списанные материалы вернутся на склад. Сделка получит статус «Отменена» и закроется.'
                : 'Сделка получит статус «Отменена» и закроется. Обратно открыть её из интерфейса нельзя.')
            ->schema([
                Textarea::make('reason')->label('Причина')->required()->rows(2),
            ])
            ->modalSubmitActionLabel('Да, отменить')
            ->authorize(fn (Deal $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->visible(fn (Deal $record): bool => ! $record->isFactoryOrder() && ! $record->status_id->isClosed())
            ->action(function (Deal $record, array $data, DoorProductionService $production, $livewire): void {
                try {
                    $production->cancelDeal($record, auth()->user(), $data['reason']);
                } catch (ProductionException $e) {
                    Notification::make()->danger()->title('Сделка не отменена')->body($e->getMessage())->send();

                    return;
                }

                self::refreshCard($livewire);
                Notification::make()->success()->title("Сделка {$record->number} отменена")->send();
            });
    }

    public static function recalculate(): Action
    {
        return Action::make('recalculate')
            ->label('Пересчитать цену')
            ->icon('heroicon-o-calculator')
            ->color('gray')
            ->authorize(fn (Deal $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->visible(fn (Deal $record): bool => $record->hasDoorConfigurations())
            ->action(function (Deal $record, DoorProductionService $production, $livewire): void {
                $production->syncPricing($record);
                self::refreshCard($livewire);

                Notification::make()->success()->title('Цена пересчитана по спецификации')->send();
            });
    }

    public static function track(): Action
    {
        return Action::make('track')
            ->label('Страница клиента')
            ->icon('heroicon-o-globe-alt')
            ->color('gray')
            ->url(fn (Deal $record): string => route('track.show', $record->qr_code_hash))
            ->openUrlInNewTab();
    }

    /** Стикер на изделие: по QR клиент и кладовщик попадают на один и тот же статус заказа. */
    public static function qr(): Action
    {
        return Action::make('qr')
            ->label('QR для двери')
            ->icon('heroicon-o-qr-code')
            ->color('gray')
            ->modalHeading(fn (Deal $record): string => "Заказ {$record->number}")
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Закрыть')
            ->modalContent(fn (Deal $record): HtmlString => new HtmlString(
                '<div style="text-align:center">'
                .app(QrCodeService::class)->svg($record->qr_code_hash ? route('track.show', $record->qr_code_hash) : url('/'), 240)
                .'<p style="margin-top:.75rem;font-size:.75rem;word-break:break-all">'
                .e(route('track.show', $record->qr_code_hash))
                .'</p></div>',
            ));
    }

    /** @param list<Action> $actions */
    private static function group(Department $department, array $actions): ActionGroup
    {
        return ActionGroup::make($actions)
            ->label($department->getLabel())
            ->icon($department->getIcon())
            ->color('gray')
            ->button();
    }

    private static function finishesOrder(Deal $record): bool
    {
        $stage = $record->currentStage;

        return $stage !== null && ($stage->completes_production || $stage->next() === null);
    }

    /**
     * После действия карточка должна показать новые платежи, этап и статус.
     * Страницы карточки умеют перечитать форму (`refreshCard`), таблицы
     * перерисовываются сами.
     */
    private static function refreshCard(mixed $livewire): void
    {
        if (is_object($livewire) && method_exists($livewire, 'refreshCard')) {
            $livewire->refreshCard();
        }
    }
}
