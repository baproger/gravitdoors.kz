<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Schemas;

use App\Enums\ClientType;
use App\Enums\DealSource;
use App\Enums\DealStatus;
use App\Enums\PaymentMethod;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\User;
use App\Support\Money;
use Filament\Forms\Components\DatePicker;
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
                    ->maxLength(255),

                TextInput::make('client_phone')
                    ->label('Телефон')
                    ->tel()
                    ->placeholder('+7 700 000 00 00')
                    ->required(),

                TextInput::make('client_phone_extra')->label('Доп. телефон')->tel(),
                TextInput::make('client_email')->label('E-mail')->email(),

                // Реквизиты нужны только компании — физлицу они лишний шум.
                TextInput::make('client_company')
                    ->label('Название компании')
                    ->placeholder('ТОО «Строй-Инвест»')
                    ->required(fn (Get $get): bool => $get('client_type') === ClientType::Company->value)
                    ->visible(fn (Get $get): bool => self::isCompany($get('client_type'))),

                TextInput::make('client_bin')
                    ->label('БИН / ИИН')
                    ->maxLength(20)
                    ->visible(fn (Get $get): bool => self::isCompany($get('client_type'))),

                TextInput::make('city')->label('Город')->placeholder('Алматы'),
                DatePicker::make('measured_at')->label('Дата замера')->displayFormat('d.m.Y'),

                Textarea::make('client_address')
                    ->label('Адрес объекта')
                    ->rows(2)
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
                ->columns(['default' => 1, 'sm' => 2, '2xl' => 3])
                ->schema(DoorConfigurationSchema::components())
                ->itemLabel(fn (array $state): string => self::positionLabel($state))
                ->addActionLabel('Добавить дверь')
                ->defaultItems(1)
                ->reorderable(false)
                ->collapsible()
                // Свёрнуто по умолчанию: в заказе на пять дверей развёрнутые
                // позиции превращают карточку в бесконечную простыню.
                ->collapsed(fn (?Deal $record): bool => ($record?->doorConfigurations()->count() ?? 0) > 1)
                ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                    $data['position'] ??= 1;

                    return $data;
                }),
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
                    ->default(0)
                    ->suffix(config('gravit.currency.symbol'))
                    ->live(onBlur: true),

                Select::make('payment_method')
                    ->label('Способ оплаты')
                    ->options(PaymentMethod::class)
                    ->native(false),

                TextInput::make('delivery_cost')
                    ->label('Доставка')
                    ->helperText('Прибавляется к сумме сделки')
                    ->numeric()
                    ->default(0)
                    ->suffix(config('gravit.currency.symbol'))
                    ->live(onBlur: true),

                TextInput::make('installation_cost')
                    ->label('Монтаж')
                    ->helperText('Прибавляется к сумме сделки')
                    ->numeric()
                    ->default(0)
                    ->suffix(config('gravit.currency.symbol'))
                    ->live(onBlur: true),

                TextInput::make('total_price')
                    ->label('Сумма сделки')
                    ->helperText('Пересчитывается из спецификации и услуг при сохранении')
                    ->numeric()
                    ->default(0)
                    ->suffix(config('gravit.currency.symbol')),

                TextInput::make('cost_price')
                    ->label('Себестоимость')
                    ->numeric()
                    ->default(0)
                    ->suffix(config('gravit.currency.symbol')),
            ]),
        ];
    }

    /** @return list<mixed> */
    private static function contractFields(): array
    {
        return [
            Grid::make(2)->schema([
                TextInput::make('contract_number')->label('№ договора'),
                DatePicker::make('contract_date')->label('Дата договора')->displayFormat('d.m.Y'),

                Textarea::make('notes')
                    ->label('Заметка по сделке')
                    ->rows(4)
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
                    ->default(fn (): string => now()->addWeeks(3)->toDateString()),

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
        $size = filled($state['height'] ?? null) && filled($state['width'] ?? null)
            ? " · {$state['height']}×{$state['width']}"
            : '';
        $label = filled($state['label'] ?? null) ? " · {$state['label']}" : '';

        return "Позиция {$position}{$label}{$size}";
    }

    private static function summary(Get $get, ?Deal $record): HtmlString
    {
        $doors = (float) ($record?->doorConfigurations()->sum('calculated_price') ?? 0);
        $delivery = (float) ($get('delivery_cost') ?? 0);
        $installation = (float) ($get('installation_cost') ?? 0);
        $total = (float) ($get('total_price') ?? 0);
        $cost = (float) ($get('cost_price') ?? 0);
        $prepayment = (float) ($get('prepayment') ?? 0);

        $rows = [
            ['Двери по спецификации', Money::format($doors), false],
            ['Доставка', Money::format($delivery), false],
            ['Монтаж', Money::format($installation), false],
            ['Сумма сделки', Money::format($total), true],
            ['Предоплата', Money::format($prepayment), false],
            ['Остаток к оплате', Money::format(max(0, $total - $prepayment)), true],
        ];

        if ($total > 0) {
            $rows[] = ['Маржа', Money::format($total - $cost).' · '.round(($total - $cost) / $total * 100, 1).' %', false];
        }

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
