<?php

declare(strict_types=1);

namespace App\Filament\Resources\DoorOptions\Schemas;

use App\Enums\DoorOptionCategory;
use App\Enums\PriceType;
use App\Models\MaterialStock;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class DoorOptionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Позиция прайса')
                ->columns(2)
                ->schema([
                    Select::make('category')
                        ->label('Группа')
                        ->options(DoorOptionCategory::class)
                        ->required()
                        ->native(false),

                    TextInput::make('label')
                        ->label('Название для менеджера')
                        ->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (?string $state, callable $set, callable $get): void {
                            if (blank($get('code')) && filled($state)) {
                                $set('code', Str::slug($state, '_'));
                            }
                        }),

                    TextInput::make('code')->label('Код')->required()->alphaDash()->maxLength(64),

                    TextInput::make('sort')->label('Порядок в списке')->numeric()->default(0),
                ]),

            Section::make('Цена')
                ->columns(2)
                ->schema([
                    TextInput::make('price')
                        ->label('Цена')
                        ->numeric()
                        ->required()
                        ->default(0)
                        ->suffix(config('gravit.currency.symbol')),

                    Select::make('price_type')
                        ->label('Как считается')
                        ->options(PriceType::class)
                        ->default(PriceType::Fixed->value)
                        ->required()
                        ->native(false)
                        ->helperText('«За м²» умножает цену на площадь полотна — это и есть «габариты × материал».'),
                ]),

            Section::make('Списание со склада')
                ->description('Необязательно. Если материал указан, при передаче наряда в цех остаток спишется автоматически.')
                ->columns(2)
                ->schema([
                    Select::make('material_stock_id')
                        ->label('Материал')
                        ->options(fn (): array => MaterialStock::query()->where('is_active', true)->pluck('name', 'id')->all())
                        ->searchable()
                        ->native(false),

                    TextInput::make('consumption')
                        ->label('Расход на единицу расчёта')
                        ->helperText('На 1 м² полотна, 1 м.п. периметра или на изделие — в зависимости от типа цены.')
                        ->numeric()
                        ->default(0),

                    Toggle::make('is_default')->label('Выбирать по умолчанию'),
                    Toggle::make('is_active')->label('Активна')->default(true),
                ]),
        ]);
    }
}
