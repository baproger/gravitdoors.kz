<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Schemas;

use App\Enums\DoorOptionCategory;
use App\Enums\OpeningSide;
use App\Models\DoorOption;
use App\Services\DoorPriceCalculator;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;

/**
 * Спецификация двери + живой предпросмотр цены.
 *
 * Предпросмотр — именно предпросмотр: авторитетный расчёт делает
 * DoorProductionService::syncPricing() при сохранении. Иначе цена в базе
 * зависела бы от того, дособрал ли менеджер форму до конца.
 */
class DoorConfigurationSchema
{
    /**
     * @param  bool  $withPreview  На странице калькулятора предпросмотр выключен —
     *                             там итог показывает Bento-панель.
     * @return list<Component|Field>
     */
    public static function components(bool $withPreview = true): array
    {
        return array_values(array_filter([
            TextInput::make('position')
                ->label('№ позиции')
                ->numeric()
                ->minValue(1)
                ->default(1)
                ->required(),

            TextInput::make('label')
                ->label('Назначение')
                ->placeholder('Входная в квартиру')
                ->maxLength(255)
                ->columnSpan(3),

            TextInput::make('height')
                ->label('Высота, мм')
                ->numeric()
                ->required()
                ->default(config('gravit.pricing.default_height'))
                ->minValue(config('gravit.pricing.min_height'))
                ->maxValue(config('gravit.pricing.max_height'))
                ->live(onBlur: true),

            TextInput::make('width')
                ->label('Ширина, мм')
                ->numeric()
                ->required()
                ->default(config('gravit.pricing.default_width'))
                ->minValue(config('gravit.pricing.min_width'))
                ->maxValue(config('gravit.pricing.max_width'))
                ->live(onBlur: true),

            Select::make('opening_side')
                ->label('Сторона открывания')
                ->options(OpeningSide::class)
                ->default(OpeningSide::Right->value)
                ->required()
                ->native(false),

            TextInput::make('quantity')
                ->label('Количество, шт')
                ->numeric()
                ->minValue(1)
                ->default(1)
                ->required()
                ->live(onBlur: true),

            ...self::optionSelect(DoorOptionCategory::MetalThickness, required: true),
            ...self::optionSelect(DoorOptionCategory::ColorCoating),
            ...self::optionSelect(DoorOptionCategory::OuterMdfPanel),
            ...self::optionSelect(DoorOptionCategory::InnerMdfPanel),
            ...self::optionSelect(DoorOptionCategory::InsulationType),
            ...self::optionSelect(DoorOptionCategory::LockSystem, required: true),

            CheckboxList::make('additional_options')
                ->label(DoorOptionCategory::Additional->getLabel())
                ->options(fn (): array => self::options(DoorOptionCategory::Additional))
                ->descriptions(fn (): array => self::priceHints(DoorOptionCategory::Additional))
                ->columns(2)
                ->live()
                ->columnSpanFull(),

            Textarea::make('comment')
                ->label('Комментарий для цеха')
                ->rows(2)
                ->columnSpanFull(),

            $withPreview
                ? Placeholder::make('price_preview')
                    ->label('Предварительный расчёт')
                    ->content(fn (Get $get): HtmlString => self::preview($get))
                    ->columnSpanFull()
                : null,
        ]));
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

    /** Подписи с ценой под чекбоксами доп. опций. @return array<string, string> */
    private static function priceHints(DoorOptionCategory $category): array
    {
        return DoorOption::query()
            ->ofCategory($category)
            ->active()
            ->orderBy('sort')
            ->get()
            ->mapWithKeys(fn (DoorOption $option): array => [
                $option->code => number_format((float) $option->price, 0, ',', ' ')
                    .' '.config('gravit.currency.symbol').' · '.$option->price_type->getLabel(),
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

        $symbol = config('gravit.currency.symbol');
        $money = fn (float $value): string => number_format($value, 0, ',', ' ').' '.$symbol;

        $rows = collect($breakdown->lines)
            ->map(fn (array $line): string => sprintf(
                '<div class="flex justify-between gap-4 py-0.5"><span class="text-gray-500 dark:text-gray-400">%s</span><span class="font-medium tabular-nums">%s</span></div>',
                e($line['label']),
                $money((float) $line['amount']),
            ))
            ->implode('');

        return new HtmlString(<<<HTML
            <div class="text-sm">
                <div class="mb-2 text-gray-500 dark:text-gray-400">
                    Площадь полотна {$breakdown->areaSqm} м² · периметр {$breakdown->perimeterMeters} м.п. · {$breakdown->quantity} шт
                </div>
                {$rows}
                <div class="flex justify-between gap-4 border-t border-gray-200 pt-1 dark:border-gray-700">
                    <span class="text-gray-500 dark:text-gray-400">Сборка</span>
                    <span class="font-medium tabular-nums">{$money($breakdown->assemblyCost)}</span>
                </div>
                <div class="mt-2 flex justify-between gap-4 border-t border-gray-300 pt-2 text-base dark:border-gray-600">
                    <span class="font-semibold">Итого за {$breakdown->quantity} шт</span>
                    <span class="font-bold tabular-nums text-primary-600 dark:text-primary-400">{$money($breakdown->total)}</span>
                </div>
                <div class="mt-1 flex justify-between gap-4 text-xs text-gray-500 dark:text-gray-400">
                    <span>Себестоимость ≈ {$money($breakdown->estimatedCost)}</span>
                    <span>Маржа {$breakdown->marginPercent()}%</span>
                </div>
            </div>
        HTML);
    }
}
