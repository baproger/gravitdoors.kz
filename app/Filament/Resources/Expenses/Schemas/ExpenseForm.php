<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Schemas;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Models\CashAccount;
use App\Models\Deal;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()
                ->columns(2)
                ->schema([
                    Select::make('category')
                        ->label('Категория')
                        ->options(ExpenseCategory::class)
                        ->default(ExpenseCategory::Other->value)
                        ->required()
                        ->live()
                        ->native(false),

                    TextInput::make('amount')
                        ->label('Сумма')
                        ->numeric()
                        ->minValue(1)
                        ->required()
                        ->suffix(config('gravit.currency.symbol')),

                    DatePicker::make('spent_at')
                        ->label('Дата')
                        ->displayFormat('d.m.Y')
                        ->default(now())
                        ->maxDate(now())
                        ->required(),

                    Select::make('method')
                        ->label('Способ оплаты')
                        ->options(PaymentMethod::class)
                        ->default(PaymentMethod::Cash->value)
                        ->required()
                        ->native(false),

                    Select::make('account_id')
                        ->label('Счёт')
                        ->helperText('Пусто — по способу оплаты: наличные в кассу, остальное в банк')
                        ->options(fn (): array => CashAccount::query()->where('is_active', true)->orderBy('id')->pluck('name', 'id')->all())
                        ->native(false),

                    TextInput::make('counterparty')
                        ->label('Кому')
                        ->placeholder('арендодатель, поставщик, сервис')
                        ->maxLength(255),

                    Select::make('deal_id')
                        ->label('Сделка')
                        ->helperText('Если расход по конкретному заказу: доставка, монтаж, доп. материалы')
                        ->options(fn (): array => Deal::query()->sales()->open()->orderByDesc('id')->limit(200)
                            ->get()->mapWithKeys(fn (Deal $d): array => [$d->id => "{$d->number} · {$d->title}"])->all())
                        ->searchable()
                        ->native(false),

                    Textarea::make('comment')->label('Комментарий')->rows(2)->columnSpanFull(),

                    FileUpload::make('receipt_path')
                        ->label('Чек')
                        // В форме категория приходит строкой, при редактировании — enum'ом.
                        ->helperText(function (Get $get): string {
                            $category = $get('category');
                            $category = $category instanceof ExpenseCategory ? $category : ExpenseCategory::tryFrom((string) $category);

                            return ($category?->requiresReceipt() ?? true)
                                ? 'Обязателен для подтверждения. Фото или PDF, до 10 МБ'
                                : 'Для зарплаты и налогов не нужен';
                        })
                        ->directory('expenses')
                        ->disk('public')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                        ->maxSize(10240)
                        ->openable()
                        ->downloadable()
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
