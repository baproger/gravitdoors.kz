<?php

declare(strict_types=1);

namespace App\Filament\Resources\Debts\Schemas;

use App\Enums\DebtCategory;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DebtForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextInput::make('counterparty')->label('Кому должны')->required()->maxLength(255)->columnSpanFull(),
                    Select::make('category')->label('Категория')->options(DebtCategory::class)->default(DebtCategory::Supplier->value)->required()->native(false),
                    TextInput::make('amount')->label('Сумма долга')->numeric()->minValue(1)->required()->suffix(config('gravit.currency.symbol')),
                    DatePicker::make('due_at')->label('Срок оплаты')->displayFormat('d.m.Y'),
                    FileUpload::make('document_path')
                        ->label('Документ')
                        ->helperText('Счёт, договор, акт — до 10 МБ')
                        ->directory('debts')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                        ->maxSize(10240)
                        ->openable(),
                    Textarea::make('comment')->label('Комментарий')->rows(2)->columnSpanFull(),
                ]),
        ]);
    }
}
