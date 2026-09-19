<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Schemas;

use App\Enums\ClientType;
use App\Enums\DealSource;
use App\Enums\DealStatus;
use App\Enums\DoorCategory;
use App\Enums\DoorModel;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Filament\Actions\DealActions;
use App\Models\CashAccount;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\User;
use App\Services\AccessControl;
use App\Support\Money;
use App\Support\Validation;
use Carbon\Carbon;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\HtmlString;

/**
 * Карточка сделки в духе amoCRM: сверху — шаги воронки одним кликом,
 * слева — вкладки со всеми данными клиента и дверей, справа — сводка по деньгам,
 * которая видна всегда, на какой бы вкладке ни находился менеджер.
 */
class DealForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // Полоса этапов. Заменяет выпадающий список: менеджеру важно видеть,
            // где сделка стоит и что идёт следом, а не выбирать из списка.
            View::make('filament.deals.stage-stepper')
                ->visible(fn (?Deal $record): bool => $record !== null)
                ->columnSpanFull(),

            // Схема страницы редактирования по умолчанию двухколоночная,
            // поэтому сетку карточки растягиваем явно — иначе она занимала
            // половину экрана, а вторая половина пустовала.
            Grid::make(12)->columnSpanFull()->schema([
                Tabs::make('Карточка')
                    ->columnSpan(['default' => 12, 'xl' => 8])
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('Клиент')
                            ->icon('heroicon-o-user')
                            ->schema(self::clientFields()),

                        Tab::make('Двери')
                            ->icon('heroicon-o-square-3-stack-3d')
                            ->badge(fn (?Deal $record): ?string => ($count = $record?->doorConfigurations()->count() ?? 0) > 0 ? (string) $count : null)
                            ->visible(fn (Get $get): bool => self::isSalesPipeline($get('pipeline_type')))
                            ->schema(self::doorFields()),

                        Tab::make('Оплата')
                            ->icon('heroicon-o-banknotes')
                            ->visible(fn (): bool => AccessControl::can(Permission::KanbanMoney))
                            ->schema(self::paymentFields()),

                        Tab::make('Договор')
                            ->icon('heroicon-o-document-text')
                            ->schema(self::contractFields()),
                    ]),

                Group::make()
                    ->columnSpan(['default' => 12, 'xl' => 4])
                    ->schema([
                        self::summarySection(),
                        self::controlSection(),
                    ]),
            ]),
        ]);
    }

    /** @return list<mixed> */
    private static function clientFields(): array
    {
        return [
            Grid::make(2)->schema([
                Select::make('client_type')
                    ->label('Тип клиента')
                    ->options(ClientType::class)
                    ->default(ClientType::Individual->value)
                    ->required()
                    ->live()
                    ->native(false),

                Select::make('source')
                    ->label('Откуда пришёл')
                    ->options(DealSource::class)
                    ->searchable()
                    ->native(false),

                TextInput::make('client_name')
                    ->label('Контактное лицо')
                    ->placeholder('Асхат Жумабеков')
                    ->required()
                    ->minLength(2)
                    ->maxLength(255),

                TextInput::make('client_phone')
                    ->label('Телефон')
                    ->tel()
                    ->telRegex(Validation::PHONE_REGEX)
                    ->mask(Validation::PHONE_MASK)
                    ->placeholder('+7 (700) 000-00-00')
                    ->required()
                    ->rule(static fn (): Closure => Validation::phone()),

                TextInput::make('client_phone_extra')
                    ->label('Доп. телефон')
                    ->tel()
                    ->telRegex(Validation::PHONE_REGEX)
                    ->mask(Validation::PHONE_MASK)
                    ->placeholder('+7 (700) 000-00-00')
                    ->rule(static fn (): Closure => Validation::phone()),

                TextInput::make('client_email')
                    ->label('E-mail')
                    ->email()
                    ->maxLength(255)
                    ->placeholder('client@mail.kz'),

                // Реквизиты нужны только компании — физлицу они лишний шум.
                TextInput::make('client_company')
                    ->label('Название компании')
                    ->placeholder('ТОО «Строй-Инвест»')
                    ->required(fn (Get $get): bool => self::isCompany($get('client_type')))
                    ->visible(fn (Get $get): bool => self::isCompany($get('client_type'))),

                TextInput::make('client_bin')
                    ->label('БИН / ИИН')
                    ->placeholder('150340012345')
                    ->maxLength(12)
                    ->rule(static fn (): Closure => Validation::bin())
                    ->required(fn (Get $get): bool => self::isCompany($get('client_type')))
                    ->visible(fn (Get $get): bool => self::isCompany($get('client_type'))),

                TextInput::make('city')->label('Город')->placeholder('Алматы')->maxLength(100),

                // Со временем: замерщику нужен час выезда, а не «в этот день».
                DateTimePicker::make('measured_at')
                    ->label('Дата и время замера')
                    ->displayFormat('d.m.Y H:i')
                    ->seconds(false)
                    ->minutesStep(15)
                    ->helperText('При сохранении замерщик получит уведомление с датой, временем и адресом')
                    ->hint(fn (?Deal $record): ?string => $record?->isMeasurementOverdue()
                        ? 'Просрочен на '.$record->measurementOverdueDays().' дн.'
                        : null)
                    ->hintColor('danger')
                    ->hintIcon(fn (?Deal $record): ?string => $record?->isMeasurementOverdue() ? 'heroicon-m-exclamation-triangle' : null),

                Textarea::make('client_address')
                    ->label('Адрес объекта')
                    ->rows(2)
                    ->maxLength(500)
                    ->placeholder('ЖК «Алатау», ул. Розыбакиева 247, кв. 45')
                    ->columnSpanFull(),
            ]),

            self::measurementSection(),
        ];
    }

    /**
     * Что замерщик привёз с выезда.
     *
     * Отдельным блоком, а не в «Дверях»: габариты двери — это заказ, а это факт
     * проёма, с которым заказ ещё предстоит свести. Заполняется кнопкой
     * «Замерял» с инфопанели, но менеджер может поправить руками.
     */
    private static function measurementSection(): Section
    {
        return Section::make('Результат замера')
            ->icon('heroicon-o-clipboard-document-check')
            ->columns(2)
            ->columnSpanFull()
            ->visible(fn (Get $get): bool => self::isSalesPipeline($get('pipeline_type')))
            ->schema([
                Placeholder::make('measurement_done')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->content(function (?Deal $record): string {
                        if (! $record?->hasMeasurement()) {
                            return 'Замер ещё не проведён. Замерщик запишет размеры кнопкой «Замерял» на инфопанели.';
                        }

                        $who = $record->measuredBy?->name;

                        return 'Замерял: '.($who !== null ? $who : '—')
                            .' · '.$record->measurement_done_at->format('d.m.Y H:i');
                    }),

                TextInput::make('measurement_height')
                    ->label('Высота проёма, мм')
                    ->numeric()
                    ->minValue(config('gravit.pricing.min_height'))
                    ->maxValue(config('gravit.pricing.max_height')),

                TextInput::make('measurement_width')
                    ->label('Ширина проёма, мм')
                    ->numeric()
                    ->minValue(config('gravit.pricing.min_width'))
                    ->maxValue(config('gravit.pricing.max_width')),

                Textarea::make('measurement_comment')
                    ->label('Комментарий замерщика')
                    ->rows(2)
                    ->maxLength(1000)
                    ->placeholder('Проём кривой, нужен доборный профиль')
                    ->columnSpanFull(),
            ]);
    }

    /** @return list<mixed> */
    private static function doorFields(): array
    {
        return [
            Repeater::make('doorConfigurations')
                ->hiddenLabel()
                ->relationship()
                // Одна колонка: внутри позиции лежат самостоятельные блоки
                // («Изделие», «Размеры», «Характеристики»), и деление репитера
                // на колонки сплющивало их в нечитаемые полоски.
                ->columns(1)
                ->schema(DoorConfigurationSchema::components())
                ->itemLabel(fn (array $state): string => self::positionLabel($state))
                ->addActionLabel('Добавить дверь')
                ->defaultItems(1)
                ->reorderable(false)
                ->collapsible()
                // Свёрнуто по умолчанию: в заказе на пять дверей развёрнутые
                // позиции превращают карточку в бесконечную простыню.
                ->collapsed(fn (?Deal $record): bool => ($record?->doorConfigurations()->count() ?? 0) > 1)
                // Номер позиции больше не поле формы: его проставляет
                // DoorConfigurationObserver и перенумеровывает после удаления.
                ->addable()
                ->deletable(),
        ];
    }

    /** @return list<mixed> */
    private static function paymentFields(): array
    {
        return [
            // Кнопка «Принять оплату» — первое, что видно на вкладке: она подставляет
            // остаток и сохраняет платёж сразу, без «Сохранить» внизу карточки.
            // Список ниже — те же платежи, их можно поправить и сохранить формой.
            Section::make('Оплата')
                ->icon('heroicon-o-banknotes')
                ->visible(fn (?Deal $record): bool => $record !== null && ! $record->isFactoryOrder())
                ->schema([
                    Placeholder::make('payment_state')
                        ->hiddenLabel()
                        ->content(fn (?Deal $record): HtmlString => self::paymentState($record)),

                    Actions::make([DealActions::pay()])->alignStart(),
                ]),

            Section::make('Платежи клиента')
                ->description('Каждый платёж — с чеком. Сумма платежей становится предоплатой сделки.')
                ->schema([
                    Repeater::make('payments')
                        ->hiddenLabel()
                        ->relationship()
                        // Три поля в ряд: при четырёх «Kaspi перевод» в выпадающем
                        // списке переносился на две строки.
                        ->columns(['default' => 1, 'md' => 3])
                        ->defaultItems(0)
                        ->addActionLabel('Добавить платёж')
                        ->reorderable(false)
                        ->collapsible()
                        ->itemLabel(fn (array $state): string => self::paymentLabel($state))
                        ->schema([
                            TextInput::make('amount')
                                ->label('Сумма')
                                ->numeric()
                                ->minValue(1)
                                ->required()
                                ->suffix(config('gravit.currency.symbol'))
                                ->live(onBlur: true),

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
                                ->displayFormat('d.m.Y')
                                ->default(now())
                                ->maxDate(now())
                                ->required(),

                            TextInput::make('comment')
                                ->label('Комментарий')
                                ->placeholder('№ операции, кто платил')
                                ->maxLength(255)
                                ->columnSpanFull(),

                            // Без чека платёж не принимаем: бухгалтерии нужно подтверждение,
                            // а «клиент сказал, что перевёл» сверить потом нечем.
                            FileUpload::make('receipt_path')
                                ->label('Чек')
                                ->helperText('Фото или PDF чека, до 10 МБ')
                                ->required()
                                ->directory('receipts')
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                                ->maxSize(10240)
                                ->openable()
                                ->downloadable()
                                ->columnSpanFull(),
                        ])
                        ->rule(static function (Get $get): Closure {
                            return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                $total = (float) ($get('total_price') ?? 0);
                                $paid = self::paidSum($value);

                                if ($total > 0 && $paid > $total) {
                                    $fail('Сумма платежей ('.Money::format($paid).') больше суммы сделки ('.Money::format($total).').');
                                }
                            };
                        }),
                ]),

            Grid::make(2)->schema([
                TextInput::make('delivery_cost')
                    ->label('Доставка')
                    ->helperText('Прибавляется к сумме сделки')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->suffix(config('gravit.currency.symbol'))
                    ->live(onBlur: true),

                TextInput::make('installation_cost')
                    ->label('Монтаж')
                    ->helperText('Прибавляется к сумме сделки')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->suffix(config('gravit.currency.symbol'))
                    ->live(onBlur: true),

                TextInput::make('total_price')
                    ->label('Сумма сделки')
                    ->helperText('Считается из спецификации и услуг при сохранении; руками не меняется')
                    ->numeric()
                    ->default(0)
                    // Только для чтения: сумма — результат расчёта, а не поле ввода.
                    // Введённое руками число всё равно перетиралось бы при сохранении.
                    ->disabled()
                    ->dehydrated(false)
                    ->suffix(config('gravit.currency.symbol'))
                    ->columnSpanFull(),
            ]),
        ];
    }

    /** @return list<mixed> */
    private static function contractFields(): array
    {
        return [
            Grid::make(2)->schema([
                TextInput::make('contract_number')
                    ->label('№ договора')
                    ->placeholder('ДГ-2026-001')
                    ->maxLength(64),

                DatePicker::make('contract_date')
                    ->label('Дата договора')
                    ->displayFormat('d.m.Y')
                    ->maxDate(now()->addYear()),

                FileUpload::make('documents')
                    ->label('Документы по сделке')
                    ->helperText('Подписанный договор, счёт, акт. PDF, фото или Word, до 10 МБ каждый.')
                    ->multiple()
                    ->reorderable()
                    ->openable()
                    ->downloadable()
                    ->directory('deals')
                    ->acceptedFileTypes([
                        'application/pdf',
                        'image/jpeg',
                        'image/png',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'text/plain',
                    ])
                    ->maxSize(10240)
                    ->maxFiles(10)
                    ->columnSpanFull(),

                Textarea::make('notes')
                    ->label('Заметка по сделке')
                    ->rows(4)
                    ->maxLength(2000)
                    ->placeholder('Договорённости, особые условия, что обещали клиенту')
                    ->columnSpanFull(),
            ]),
        ];
    }

    private static function summarySection(): Section
    {
        return Section::make('Сводка')
            ->icon('heroicon-o-calculator')
            ->visible(fn (): bool => AccessControl::can(Permission::KanbanMoney))
            ->schema([
                Placeholder::make('summary')
                    ->hiddenLabel()
                    ->content(fn (Get $get, ?Deal $record): HtmlString => self::summary($get, $record)),
            ]);
    }

    private static function controlSection(): Section
    {
        return Section::make('Управление')
            ->icon('heroicon-o-adjustments-horizontal')
            ->columns(1)
            ->schema([
                TextInput::make('title')
                    ->label('Название сделки')
                    ->placeholder('ЖК «Алатау», кв. 45')
                    ->required()
                    ->minLength(3)
                    ->maxLength(255),

                Select::make('manager_id')
                    ->label('Ответственный')
                    ->options(fn (): array => User::query()
                        ->whereIn('role', [UserRole::Manager->value, UserRole::Admin->value])
                        ->where('is_active', true)
                        ->pluck('name', 'id')
                        ->all())
                    ->default(fn (): ?int => auth()->id())
                    ->searchable()
                    ->native(false),

                Select::make('status_id')
                    ->label('Статус')
                    ->options(DealStatus::class)
                    ->default(DealStatus::New->value)
                    ->required()
                    // Только для чтения: статус выводится из этапа и завода. Руками
                    // его меняли в обход воронки — сделка значилась «Готово к отгрузке»
                    // на этапе замера. Отмена — отдельной кнопкой в шапке карточки.
                    ->disabled()
                    ->dehydrated(fn (?string $operation): bool => $operation === 'create')
                    ->helperText('Статус следует за этапом. Отменить сделку — кнопкой «Отменить сделку» сверху.')
                    ->native(false),

                DatePicker::make('due_date')
                    ->label('Срок сдачи')
                    ->displayFormat('d.m.Y')
                    // Обязателен: без срока нечему просрочиться, и сделка никогда
                    // не попадёт в «Просроченные».
                    ->required()
                    ->default(fn (): string => now()->addWeeks(3)->toDateString())
                    // Срок в прошлом можно оставить у старых сделок, но новую
                    // так не заведёшь: это всегда опечатка.
                    ->minDate(fn (?string $operation): ?string => $operation === 'create' ? now()->toDateString() : null),

                Select::make('pipeline_type')
                    ->label('Воронка')
                    ->options(PipelineType::class)
                    ->default(PipelineType::Sales->value)
                    ->required()
                    ->live()
                    ->native(false)
                    // Наряды создаёт автоматика из сделки продаж, а не менеджер руками:
                    // созданный вручную «наряд» вставал бы на этап продаж и не двигался.
                    ->disabled()
                    ->dehydrated(fn (?string $operation): bool => $operation === 'create'),

                Select::make('current_stage_id')
                    ->label('Этап')
                    ->options(fn (Get $get): array => FactoryStage::query()
                        ->ofPipeline(self::pipelineOf($get('pipeline_type')))
                        ->active()
                        ->ordered()
                        ->pluck('name', 'id')
                        ->all())
                    ->default(fn (): ?int => FactoryStage::firstOf(PipelineType::Sales)?->id)
                    // Только для чтения. Поле писало этап напрямую — в обход порядка
                    // этапов, обязательных полей и завода: так сделку можно было
                    // перевести на «Готово к отгрузке», пока дверь ещё в цеху.
                    // При создании сохраняется первый этап воронки.
                    ->disabled()
                    ->dehydrated(fn (?string $operation): bool => $operation === 'create')
                    ->helperText('Этап меняется полосой этапов сверху или на канбане — там проверяются правила воронки.')
                    ->native(false),
            ]);
    }

    /** Сумма платежей из состояния репитера — для сводки и проверки лимита. */
    private static function paidSum(mixed $payments): float
    {
        return round((float) collect(is_array($payments) ? $payments : [])
            ->sum(fn (mixed $payment): float => (float) (is_array($payment) ? ($payment['amount'] ?? 0) : 0)), 2);
    }

    /** @param array<string, mixed> $state */
    private static function paymentLabel(array $state): string
    {
        $amount = filled($state['amount'] ?? null) ? Money::format((float) $state['amount']) : 'Новый платёж';
        $method = self::enumLabel(PaymentMethod::class, $state['method'] ?? null);

        $date = $state['paid_at'] ?? null;
        $date = $date instanceof \DateTimeInterface ? $date->format('d.m.Y')
            : (filled($date) ? Carbon::parse((string) $date)->format('d.m.Y') : null);

        $receipt = filled($state['receipt_path'] ?? null) ? 'чек есть' : 'без чека';

        return collect([$amount, $method, $date, $receipt])->filter()->implode(' · ');
    }

    /** @param array<string, mixed> $state */
    private static function positionLabel(array $state): string
    {
        $position = $state['position'] ?? 1;

        $product = collect([
            self::enumLabel(DoorCategory::class, $state['category'] ?? null),
            self::enumLabel(DoorModel::class, $state['model'] ?? null),
        ])->filter()->implode(' ');

        $size = filled($state['height'] ?? null) && filled($state['width'] ?? null)
            ? " · {$state['height']}×{$state['width']} мм"
            : '';

        $quantity = ($state['quantity'] ?? 1) > 1 ? " · {$state['quantity']} шт" : '';

        return "Позиция {$position}".($product !== '' ? " · {$product}" : '').$size.$quantity;
    }

    /**
     * Состояние репитера отдаёт то строку, то enum — приводим к подписи и там, и там.
     *
     * @param  class-string<\BackedEnum&HasLabel>  $enum
     */
    private static function enumLabel(string $enum, mixed $value): ?string
    {
        if ($value instanceof $enum) {
            return $value->getLabel();
        }

        return blank($value) ? null : $enum::tryFrom((string) $value)?->getLabel();
    }

    /** Одной строкой: сколько по договору, сколько внесено, сколько осталось. */
    private static function paymentState(?Deal $record): HtmlString
    {
        if (! $record) {
            return new HtmlString('');
        }

        $remaining = $record->remainingPayment();
        $rows = [
            ['Сумма сделки', Money::format((float) $record->total_price), false],
            ['Оплачено', Money::format((float) $record->prepayment), false],
            [$remaining > 0 ? 'Остаток к оплате' : 'Оплачено полностью', $remaining > 0 ? Money::format($remaining) : '✓', true],
        ];

        $html = collect($rows)
            ->map(fn (array $row): string => sprintf(
                '<div class="gravit-line%s"><span>%s</span><span>%s</span></div>',
                $row[2] ? ' gravit-line--total' : '',
                e($row[0]),
                e($row[1]),
            ))
            ->implode('');

        return new HtmlString('<div class="gravit-lines">'.$html.'</div>');
    }

    private static function summary(Get $get, ?Deal $record): HtmlString
    {
        $doors = (float) ($record?->doorConfigurations()->sum('calculated_price') ?? 0);
        $delivery = (float) ($get('delivery_cost') ?? 0);
        $installation = (float) ($get('installation_cost') ?? 0);
        $total = (float) ($get('total_price') ?? 0);
        $prepayment = self::paidSum($get('payments'));

        $rows = [
            ['Двери по спецификации', Money::format($doors), false],
            ['Доставка', Money::format($delivery), false],
            ['Монтаж', Money::format($installation), false],
            ['Сумма сделки', Money::format($total), true],
            ['Оплачено', Money::format($prepayment), false],
            ['Остаток к оплате', Money::format(max(0, $total - $prepayment)), true],
        ];

        $html = collect($rows)
            ->map(fn (array $row): string => sprintf(
                '<div class="gravit-line%s"><span>%s</span><span>%s</span></div>',
                $row[2] ? ' gravit-line--total' : '',
                e($row[0]),
                e($row[1]),
            ))
            ->implode('');

        return new HtmlString('<div class="gravit-lines">'.$html.'</div>');
    }

    /**
     * Состояние формы приходит по-разному: при создании это строка из default(),
     * при редактировании — enum из каста модели. Сравнение «в лоб» со строкой
     * молча скрывало секцию позиций на странице редактирования.
     */
    private static function pipelineOf(mixed $state): PipelineType
    {
        if ($state instanceof PipelineType) {
            return $state;
        }

        return PipelineType::tryFrom((string) ($state ?: '')) ?? PipelineType::Sales;
    }

    private static function isSalesPipeline(mixed $state): bool
    {
        return self::pipelineOf($state) === PipelineType::Sales;
    }

    private static function isCompany(mixed $state): bool
    {
        $type = $state instanceof ClientType ? $state : ClientType::tryFrom((string) ($state ?: ''));

        return $type === ClientType::Company;
    }
}
