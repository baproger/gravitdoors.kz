<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaterialStocks\Schemas;

use App\Enums\MaterialUnit;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MaterialStockForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label('Наименование')->required()->columnSpanFull(),
                    TextInput::make('sku')->label('Артикул')->unique(ignoreRecord: true),

                    Select::make('unit')
                        ->label('Единица')
                        ->options(MaterialUnit::class)
                        ->default(MaterialUnit::Pieces->value)
                        ->required()
                        ->native(false),

                    TextInput::make('quantity')->label('Остаток')->numeric()->default(0)->required(),

                    TextInput::make('min_limit')
                        ->label('Минимальный остаток')
                        ->helperText('Ниже него позиция подсвечивается как «пора закупать».')
                        ->numeric()
                        ->default(0),

                    TextInput::make('price_per_unit')
                        ->label('Цена закупа за единицу')
                        ->numeric()
                        ->default(0)
                        ->suffix(config('gravit.currency.symbol')),

                    TextInput::make('supplier')->label('Поставщик'),

                    Toggle::make('is_active')->label('В обороте')->default(true),
                ]),
        ]);
    }
}
