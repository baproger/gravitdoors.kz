<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Schemas;

use App\Enums\ClientType;
use App\Enums\DealSource;
use App\Enums\DealStatus;
use App\Enums\DoorCategory;
use App\Enums\DoorModel;
use App\Enums\PaymentMethod;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\User;
use App\Support\Money;
use App\Support\Validation;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
                            ->visible(fn (): bool => auth()->user()?->role->seesMoney() ?? false)
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

                DatePicker::make('measured_at')
                    ->label('Дата замера')
                    ->displayFormat('d.m.Y')
                    ->helperText('При сохранении замерщик получит уведомление'),

                Textarea::make('client_address')
                    ->label('Адрес объекта')
                    ->rows(2)
                    ->maxLength(500)
                    ->placeholder('ЖК «Алатау», ул. Розыбакиева 247, кв. 45')
                    ->columnSpanFull(),
            ]),
        ];
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
            Grid::make(2)->schema([
                TextInput::make('prepayment')
                    ->label('Предоплата получена')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->suffix(config('gravit.currency.symbol'))
                    ->live(onBlur: true)
                    ->rule(static function (Get $get): Closure {
                        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            $total = (float) ($get('total_price') ?? 0);

                            if ($total > 0 && (float) $value > $total) {
                                $fail('Предоплата не может быть больше суммы сделки.');
                            }
                        };
                    }),

                Select::make('payment_method')
                    ->label('Способ оплаты')
                    ->options(PaymentMethod::class)
                    ->native(false),

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
                    ->helperText('Пересчитывается из спецификации и услуг при сохранении')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
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
                    ->disk('public')
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
            ->visible(fn (): bool => auth()->user()?->role->seesMoney() ?? false)
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
                    ->native(false),

                DatePicker::make('due_date')
                    ->label('Срок сдачи')
                    ->displayFormat('d.m.Y')
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
                    // Наряды создаёт автоматика из сделки продаж, а не менеджер руками.
                    ->disabled(fn (?string $operation): bool => $operation === 'edit'),

                Select::make('current_stage_id')
                    ->label('Этап')
                    ->options(fn (Get $get): array => FactoryStage::query()
                        ->ofPipeline(self::pipelineOf($get('pipeline_type')))
                        ->active()
                        ->ordered()
                        ->pluck('name', 'id')
                        ->all())
                    ->default(fn (): ?int => FactoryStage::firstOf(PipelineType::Sales)?->id)
                    ->helperText('Смена этапа отсюда не запускает автоматику — используйте полосу этапов сверху.')
                    ->native(false),
            ]);
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

    private static function summary(Get $get, ?Deal $record): HtmlString
    {
        $doors = (float) ($record?->doorConfigurations()->sum('calculated_price') ?? 0);
        $delivery = (float) ($get('delivery_cost') ?? 0);
        $installation = (float) ($get('installation_cost') ?? 0);
        $total = (float) ($get('total_price') ?? 0);
        $prepayment = (float) ($get('prepayment') ?? 0);

        $rows = [
            ['Двери по спецификации', Money::format($doors), false],
            ['Доставка', Money::format($delivery), false],
            ['Монтаж', Money::format($installation), false],
            ['Сумма сделки', Money::format($total), true],
            ['Предоплата', Money::format($prepayment), false],
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
