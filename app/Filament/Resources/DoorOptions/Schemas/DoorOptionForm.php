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
 * Позиция прайса конфигуратора — окно создания и правки.
 *
 * Группа и код при правке заблокированы: по паре «группа + код» позиция
 * записана в дверях заказов, и их смена молча выбросила бы позицию из расчёта.
 * Порядок задаётся стрелками в карточке группы, отдельного поля нет.
 */
class DoorOptionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components(self::components());
    }

    /** @return list<mixed> */
    public static function components(bool $editing = false): array
    {
        return [
            Section::make()
                ->columns(4)
                ->schema([
                    Select::make('category')
                        ->label('Группа')
                        ->options(DoorOptionCategory::class)
                        ->required()
                        ->native(false)
                        ->live()
                        ->disabled($editing)
                        ->dehydrated(! $editing)
                        ->helperText($editing ? 'Группу не меняют: позицию уже могли выбрать в дверях' : null)
                        ->columnSpan(2),

                    TextInput::make('label')
                        ->label('Название для менеджера')
                        ->required()
                        ->minLength(2)
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (?string $state, callable $set, callable $get) use ($editing): void {
                            if (! $editing && blank($get('code')) && filled($state)) {
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

                    Toggle::make('is_active')
                        ->label('В продаже')
                        ->default(true)
                        ->inline(false),

                    TextInput::make('code')
                        ->label('Код')
                        ->alphaDash()
                        ->maxLength(64)
                        ->disabled($editing)
                        ->dehydrated(! $editing)
                        ->helperText($editing
                            ? 'Не меняется: по коду позиция записана в заказах'
                            : 'Можно оставить пустым — создастся из названия')
                        ->columnSpan(2),

                    Toggle::make('is_default')
                        ->label('По умолчанию в новой двери')
                        ->helperText('В группе такой вариант один')
                        ->inline(false)
                        ->visible(fn (Get $get): bool => self::categoryOf($get('category')) !== DoorOptionCategory::Additional)
                        ->columnSpan(2),
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
        ];
    }

    private static function categoryOf(mixed $state): ?DoorOptionCategory
    {
        return $state instanceof DoorOptionCategory ? $state : DoorOptionCategory::tryFrom((string) $state);
    }
}
