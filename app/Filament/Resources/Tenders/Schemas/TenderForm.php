<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tenders\Schemas;

use App\Enums\TenderDocumentType;
use App\Enums\TenderPlatform;
use App\Enums\TenderStatus;
use App\Enums\UserRole;
use App\Models\Tender;
use App\Models\User;
use App\Support\Cities;
use App\Support\Validation;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Карточка тендера: слева — закупка, заказчик и документы, справа — сроки,
 * статус и обеспечение. Лоты — таблицей под карточкой (`LotsRelationManager`):
 * у каждого лота свои итог и кнопка «Создать сделку».
 */
class TenderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(12)->columnSpanFull()->schema([
                Group::make()
                    ->columnSpan(['default' => 12, 'xl' => 8])
                    ->schema([
                        self::purchaseSection(),
                        self::customerSection(),
                        self::documentsSection(),
                    ]),

                Group::make()
                    ->columnSpan(['default' => 12, 'xl' => 4])
                    ->schema([
                        self::controlSection(),
                        self::securitySection(),
                    ]),
            ]),
        ]);
    }

    private static function purchaseSection(): Section
    {
        return Section::make('Закупка')
            ->icon('heroicon-o-trophy')
            ->columns(2)
            ->schema([
                TextInput::make('announcement_number')
                    ->label('№ объявления')
                    ->placeholder('1234567-1')
                    ->maxLength(64),

                Select::make('platform')
                    ->label('Площадка')
                    ->options(TenderPlatform::class)
                    ->default(TenderPlatform::Goszakup->value)
                    ->required()
                    ->native(false),

                TextInput::make('title')
                    ->label('Наименование закупки')
                    ->placeholder('Поставка металлических дверей для школы № 45')
                    ->required()
                    ->minLength(3)
                    ->maxLength(255)
                    ->columnSpanFull(),

                Textarea::make('notes')
                    ->label('Заметка')
                    ->rows(3)
                    ->maxLength(2000)
                    ->placeholder('Требования к заявке, что спросить у заказчика, кто конкуренты')
                    ->columnSpanFull(),
            ]);
    }

    private static function customerSection(): Section
    {
        return Section::make('Заказчик')
            ->icon('heroicon-o-building-office-2')
            ->description('Реквизиты переедут в сделку, когда лот будет выигран')
            ->columns(2)
            ->schema([
                TextInput::make('customer_name')
                    ->label('Заказчик')
                    ->placeholder('КГУ «Школа-гимназия № 45»')
                    ->required()
                    ->maxLength(255),

                TextInput::make('customer_bin')
                    ->label('БИН')
                    ->placeholder('150340012345')
                    ->maxLength(12)
                    ->rule(static fn (): Closure => Validation::bin()),

                TextInput::make('contact_name')
                    ->label('Контактное лицо')
                    ->placeholder('Завхоз, отдел закупок')
                    ->maxLength(255),

                TextInput::make('contact_phone')
                    ->label('Телефон')
                    ->tel()
                    ->telRegex(Validation::PHONE_REGEX)
                    ->mask(Validation::PHONE_MASK)
                    ->placeholder('+7 (700) 000-00-00')
                    ->rule(static fn (): Closure => Validation::phone()),

                TextInput::make('contact_email')
                    ->label('E-mail')
                    ->email()
                    ->maxLength(255),

                Select::make('city')
                    ->label('Город')
                    ->options(fn (): array => Cities::options())
                    ->searchable()
                    ->native(false),

                Textarea::make('delivery_address')
                    ->label('Адрес поставки')
                    ->rows(2)
                    ->maxLength(500)
                    ->columnSpanFull(),
            ]);
    }

    private static function documentsSection(): Section
    {
        return Section::make('Документы тендера')
            ->icon('heroicon-o-paper-clip')
            ->description('Техспецификация, наша заявка, гарантия, протокол итогов, договор')
            ->schema([
                Repeater::make('documents')
                    ->hiddenLabel()
                    ->columns(['default' => 1, 'md' => 2])
                    ->defaultItems(0)
                    ->addActionLabel('Добавить документ')
                    ->reorderable(false)
                    ->collapsible()
                    ->itemLabel(fn (array $state): string => self::documentLabel($state))
                    ->schema([
                        Select::make('type')
                            ->label('Что за документ')
                            ->options(TenderDocumentType::class)
                            ->default(TenderDocumentType::Specification->value)
                            ->required()
                            ->native(false),

                        TextInput::make('comment')
                            ->label('Комментарий')
                            ->placeholder('Редакция от 12.09, подписана ЭЦП')
                            ->maxLength(255),

                        FileUpload::make('file')
                            ->label('Файл')
                            ->helperText('PDF, Word, Excel или фото, до 10 МБ')
                            ->required()
                            ->directory('tenders')
                            ->acceptedFileTypes([
                                'application/pdf',
                                'image/jpeg',
                                'image/png',
                                'application/msword',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            ])
                            ->maxSize(10240)
                            ->openable()
                            ->downloadable()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function controlSection(): Section
    {
        return Section::make('Сроки и статус')
            ->icon('heroicon-o-clock')
            ->columns(1)
            ->schema([
                Select::make('status')
                    ->label('Статус')
                    ->options(TenderStatus::class)
                    ->default(TenderStatus::New->value)
                    ->required()
                    ->helperText('«Выиграли» и «Проиграли» ставятся сами по итогам лотов')
                    ->native(false),

                DateTimePicker::make('deadline_at')
                    ->label('Окончание приёма заявок')
                    ->displayFormat('d.m.Y H:i')
                    ->seconds(false)
                    ->minutesStep(5)
                    ->helperText('За '.Tender::DEADLINE_WARNING_DAYS.' дня до срока придёт напоминание')
                    ->hint(fn (?Tender $record): ?string => $record?->deadlineHint())
                    ->hintColor(fn (?Tender $record): string => $record?->isDeadlineSoon() ? 'danger' : 'gray'),

                DatePicker::make('delivery_due_date')
                    ->label('Срок поставки по договору')
                    ->displayFormat('d.m.Y')
                    ->helperText('Станет сроком сдачи у сделки'),

                Select::make('manager_id')
                    ->label('Ответственный')
                    ->options(fn (): array => User::query()
                        ->whereIn('role', [UserRole::B2b->value, UserRole::Manager->value, UserRole::Admin->value])
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->default(fn (): ?int => auth()->id())
                    ->searchable()
                    ->native(false),
            ]);
    }

    private static function securitySection(): Section
    {
        return Section::make('Обеспечение заявки')
            ->icon('heroicon-o-shield-check')
            ->description('Деньги или гарантия, которые вернутся после итогов')
            ->columns(1)
            ->collapsible()
            ->schema([
                TextInput::make('security_amount')
                    ->label('Сумма обеспечения')
                    ->numeric()
                    ->minValue(0)
                    ->suffix(config('gravit.currency.symbol')),

                DatePicker::make('security_returned_at')
                    ->label('Возвращено')
                    ->displayFormat('d.m.Y')
                    ->maxDate(now()),
            ]);
    }

    /** @param array<string, mixed> $state */
    private static function documentLabel(array $state): string
    {
        $type = $state['type'] ?? null;
        $type = $type instanceof TenderDocumentType ? $type : TenderDocumentType::tryFrom((string) $type);

        return collect([$type?->getLabel() ?? 'Документ', $state['comment'] ?? null])->filter()->implode(' · ');
    }
}
