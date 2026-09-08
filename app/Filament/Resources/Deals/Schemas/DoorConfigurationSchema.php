<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Schemas;

use App\Enums\DoorCategory;
use App\Enums\DoorModel;
use App\Enums\DoorOptionCategory;
use App\Enums\OpeningSide;
use App\Models\DoorOption;
use App\Services\DoorPriceCalculator;
use App\Support\Money;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;

/**
 * Спецификация одной двери, разложенная по блокам.
 *
 * Наверху — что именно продаём (линейка, модель, количество), ниже свёрнутыми
 * блоками — размеры и материалы. Менеджеру в 90 % случаев нужен только первый
 * блок, а разворачивать характеристики он идёт осознанно.
 */
class DoorConfigurationSchema
{
    /** @return list<mixed> */
    public static function components(): array
    {
        return [
            Section::make('Изделие')
                ->columns(3)
                ->schema([
                    Select::make('category')
                        ->label('Линейка')
                        ->options(DoorCategory::class)
                        ->default(DoorCategory::Comfort->value)
                        ->required()
                        ->native(false)
                        ->live(),

                    Select::make('model')
                        ->label('Модель')
                        ->options(DoorModel::class)
                        ->required()
                        ->native(false)
                        ->live(),

                    TextInput::make('quantity')
                        ->label('Количество, шт')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(50)
                        ->default(1)
                        ->required()
                        ->live(onBlur: true),
                ]),

            Section::make('Размеры проёма')
                ->columns(3)
                ->schema([
                    TextInput::make('height')
                        ->label('Высота, мм')
                        ->numeric()
                        ->required()
                        ->default(config('gravit.pricing.default_height'))
                        ->minValue(config('gravit.pricing.min_height'))
                        ->maxValue(config('gravit.pricing.max_height'))
                        ->helperText(config('gravit.pricing.min_height').'–'.config('gravit.pricing.max_height').' мм')
                        ->live(onBlur: true),

                    TextInput::make('width')
                        ->label('Ширина, мм')
                        ->numeric()
                        ->required()
                        ->default(config('gravit.pricing.default_width'))
                        ->minValue(config('gravit.pricing.min_width'))
                        ->maxValue(config('gravit.pricing.max_width'))
                        ->helperText(config('gravit.pricing.min_width').'–'.config('gravit.pricing.max_width').' мм')
                        ->live(onBlur: true),

                    Select::make('opening_side')
                        ->label('Сторона открывания')
                        ->options(OpeningSide::class)
                        ->default(OpeningSide::Right->value)
                        ->required()
                        ->native(false),
                ]),

            Section::make('Характеристики')
                ->description('Материалы и фурнитура — из них считается цена')
                ->collapsed()
                ->columns(3)
                ->schema([
                    ...self::optionSelect(DoorOptionCategory::MetalThickness, required: true),
                    ...self::optionSelect(DoorOptionCategory::ColorCoating),
                    ...self::optionSelect(DoorOptionCategory::LockSystem, required: true),
                    ...self::optionSelect(DoorOptionCategory::OuterMdfPanel),
                    ...self::optionSelect(DoorOptionCategory::InnerMdfPanel),
                    ...self::optionSelect(DoorOptionCategory::InsulationType),

                    CheckboxList::make('additional_options')
                        ->label(DoorOptionCategory::Additional->getLabel())
                        ->options(fn (): array => self::options(DoorOptionCategory::Additional))
                        ->descriptions(fn (): array => self::priceHints(DoorOptionCategory::Additional))
                        ->columns(3)
                        ->live()
                        ->columnSpanFull(),

                    Textarea::make('comment')
                        ->label('Комментарий для цеха')
                        ->rows(2)
                        ->maxLength(500)
                        ->columnSpanFull(),
                ]),

            Placeholder::make('price_preview')
                ->label('Расчёт позиции')
                ->content(fn (Get $get): HtmlString => self::preview($get))
                ->columnSpanFull(),
        ];
    }

    /** @return list<Select> */
    private static function optionSelect(DoorOptionCategory $category, bool $required = false): array
    {
        return [
            Select::make($category->value)
                ->label($category->getLabel())
                ->options(fn (): array => self::options($category))
                ->default(fn (): ?string => self::defaultCode($category))
                ->required($required)
                ->searchable()
                ->native(false)
                ->live(),
        ];
    }

    /** @return array<string, string> */
    private static function options(DoorOptionCategory $category): array
    {
        return app(DoorPriceCalculator::class)->optionsFor($category);
    }

    /** @return array<string, string> */
    private static function priceHints(DoorOptionCategory $category): array
    {
        return DoorOption::query()
            ->ofCategory($category)
            ->active()
            ->orderBy('sort')
            ->get()
            ->mapWithKeys(fn (DoorOption $option): array => [
                $option->code => Money::format((float) $option->price).' · '.$option->price_type->getLabel(),
            ])
            ->all();
    }

    private static function defaultCode(DoorOptionCategory $category): ?string
    {
        return DoorOption::query()
            ->ofCategory($category)
            ->active()
            ->where('is_default', true)
            ->value('code');
    }

    private static function preview(Get $get): HtmlString
    {
        $breakdown = app(DoorPriceCalculator::class)->calculate([
            'height' => $get('height'),
            'width' => $get('width'),
            'quantity' => $get('quantity'),
            ...collect(DoorOptionCategory::cases())
                ->mapWithKeys(fn (DoorOptionCategory $c): array => [$c->value => $get($c->formField())])
                ->all(),
        ]);

        $rows = collect($breakdown->lines)
            ->map(fn (array $line): string => sprintf(
                '<div class="gravit-line"><span>%s</span><span>%s</span></div>',
                e($line['label']),
                e(Money::format((float) $line['amount'])),
            ))
            ->implode('');

        return new HtmlString(sprintf(
            '<div class="gravit-lines">%s<div class="gravit-line"><span>Сборка</span><span>%s</span></div>'
            .'<div class="gravit-line gravit-line--total"><span>Итого за %d шт</span><span>%s</span></div>'
            .'<div class="gravit-line"><span>Площадь полотна</span><span>%s м² · периметр %s м.п.</span></div></div>',
            $rows,
            e(Money::format($breakdown->assemblyCost)),
            $breakdown->quantity,
            e(Money::format($breakdown->total)),
            $breakdown->areaSqm,
            $breakdown->perimeterMeters,
        ));
    }
}
