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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * Позиция прайса конфигуратора.
 *
 * Раньше это были три отдельные секции на восемь полей — окно прокручивалось
 * ради двух цифр. Теперь основное в одной сетке, а списание со склада —
 * свёрнутый блок: его заполняют не для каждой позиции.
 */
class DoorOptionForm
{
    public static function configure(Schema $schema): Schema
    {
        // Схема в одну колонку: по умолчанию секции встают рядом и в модалке
        // сжимают поля до нечитаемых обрубков вроде «12 000» → «1».
        return $schema->columns(1)->components([
            Section::make()
                ->columns(4)
                ->schema([
                    Select::make('category')
                        ->label('Группа')
                        ->options(DoorOptionCategory::class)
                        ->required()
                        ->native(false)
                        ->columnSpan(2),

                    TextInput::make('label')
                        ->label('Название для менеджера')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (?string $state, callable $set, callable $get): void {
                            if (blank($get('code')) && filled($state)) {
                                $set('code', Str::slug($state, '_'));
                            }
                        })
                        ->columnSpan(2),

                    TextInput::make('price')
                        ->label('Цена')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->default(0)
                        ->suffix(config('gravit.currency.symbol')),

                    Select::make('price_type')
                        ->label('Как считается')
                        ->options(PriceType::class)
                        ->default(PriceType::Fixed->value)
                        ->required()
                        ->native(false)
                        ->helperText('«За м²» умножает цену на площадь полотна')
                        ->columnSpan(2),

                    TextInput::make('sort')
                        ->label('Порядок')
                        ->numeric()
                        ->minValue(0)
                        ->default(0),

                    TextInput::make('code')
                        ->label('Код')
                        ->required()
                        ->alphaDash()
                        ->maxLength(64)
                        ->helperText('Технический идентификатор, уникален внутри группы')
                        ->columnSpan(2),

                    Toggle::make('is_default')->label('По умолчанию')->inline(false),
                    Toggle::make('is_active')->label('Активна')->default(true)->inline(false),
                ]),

            Section::make('Списание со склада')
                ->description('Необязательно. Если материал указан, остаток спишется при передаче наряда в цех.')
                ->collapsed(fn (Get $get): bool => blank($get('material_stock_id')))
                ->columns(2)
                ->schema([
                    Select::make('material_stock_id')
                        ->label('Материал')
                        ->options(fn (): array => MaterialStock::query()->where('is_active', true)->pluck('name', 'id')->all())
                        ->searchable()
                        ->native(false),

                    TextInput::make('consumption')
                        ->label('Расход на единицу расчёта')
                        ->helperText('На 1 м², 1 м.п. или на изделие — по типу цены')
                        ->numeric()
                        ->minValue(0)
                        ->default(0),
                ]),
        ]);
    }
}
