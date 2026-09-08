<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DoorOptionCategory;
use App\Enums\PipelineType;
use App\Models\Deal;
use App\Models\DoorConfiguration;
use App\Models\DoorOption;
use App\Models\FactoryStage;
use App\Support\DealPriceSummary;
use App\Support\PriceBreakdown;
use Illuminate\Support\Collection;

/**
 * Калькулятор стоимости двери: габариты × металл + панели МДФ + фурнитура.
 *
 * Ни одна цена не зашита в код — всё берётся из door_options, поэтому
 * прайс правится в админке. Себестоимость считается из другого источника:
 * материалы (door_options.consumption × material_stocks.price_per_unit)
 * плюс сдельная оплата этапов цеха (factory_stages.operation_cost).
 */
class DoorPriceCalculator
{
    /**
     * Посчитать по сырым данным формы — используется на странице калькулятора,
     * где сохранённой конфигурации ещё нет.
     *
     * @param  array<string, mixed>  $payload  height, width, quantity + коды опций по категориям
     */
    public function calculate(array $payload): PriceBreakdown
    {
        $height = max(1, (int) ($payload['height'] ?? config('gravit.pricing.default_height')));
        $width = max(1, (int) ($payload['width'] ?? config('gravit.pricing.default_width')));
        $quantity = max(1, (int) ($payload['quantity'] ?? 1));

        $area = round($height * $width / 1_000_000, 4);
        $perimeter = round(2 * ($height + $width) / 1000, 3);

        $options = $this->resolveOptions($this->selectedCodes($payload));

        $lines = [];
        $optionsTotal = 0.0;
        $materialsCost = 0.0;

        foreach ($options as $option) {
            $amount = $option->priceFor($area, $perimeter);
            $optionsTotal += $amount;
            $materialsCost += $this->materialCost($option, $area, $perimeter);

            $lines[] = [
                'category' => $option->category->value,
                'code' => $option->code,
                'label' => $option->label,
                'price_type' => $option->price_type->value,
                'unit_price' => (float) $option->price,
                'multiplier' => $amount > 0.0 && (float) $option->price > 0.0
                    ? round($amount / (float) $option->price, 4)
                    : 1.0,
                'amount' => $amount,
            ];
        }

        $assembly = (float) config('gravit.pricing.assembly_cost');
        $markupPercent = (float) config('gravit.pricing.markup_percent');
        $markup = round(($optionsTotal + $assembly) * $markupPercent / 100, 2);

        $unitPrice = $this->round($optionsTotal + $assembly + $markup);
        $total = $this->round($unitPrice * $quantity);

        $estimatedCost = round(($materialsCost + $this->laborCost()) * $quantity, 2);

        return new PriceBreakdown(
            lines: $lines,
            areaSqm: $area,
            perimeterMeters: $perimeter,
            quantity: $quantity,
            optionsTotal: round($optionsTotal, 2),
            assemblyCost: $assembly,
            markupAmount: $markup,
            unitPrice: $unitPrice,
            total: $total,
            estimatedCost: $estimatedCost,
        );
    }

    /** Посчитать по сохранённой спецификации. */
    public function calculateFor(DoorConfiguration $configuration): PriceBreakdown
    {
        return $this->calculate([
            'height' => $configuration->height,
            'width' => $configuration->width,
            'quantity' => $configuration->quantity,
            ...$this->flattenSelected($configuration->selectedCodes()),
        ]);
    }

    /** Посчитать и записать результат в спецификацию. */
    public function applyTo(DoorConfiguration $configuration): PriceBreakdown
    {
        $breakdown = $this->calculateFor($configuration);

        $configuration->forceFill([
            'calculated_price' => $breakdown->total,
            'price_breakdown' => $breakdown->toArray(),
        ])->save();

        return $breakdown;
    }

    /** Посчитать всю сделку — все её позиции разом. */
    public function calculateForDeal(Deal $deal): DealPriceSummary
    {
        return DealPriceSummary::of(
            $deal->configurations()->map(fn (DoorConfiguration $c): PriceBreakdown => $this->calculateFor($c))->all(),
        );
    }

    /** Посчитать сделку и записать расшифровку в каждую позицию. */
    public function applyToDeal(Deal $deal): DealPriceSummary
    {
        return DealPriceSummary::of(
            $deal->configurations()->map(fn (DoorConfiguration $c): PriceBreakdown => $this->applyTo($c))->all(),
        );
    }

    /**
     * Варианты для выпадающих списков конфигуратора.
     *
     * @return array<string, string>
     */
    public function optionsFor(DoorOptionCategory $category): array
    {
        return DoorOption::query()
            ->ofCategory($category)
            ->active()
            ->orderBy('sort')
            ->orderBy('label')
            ->pluck('label', 'code')
            ->all();
    }

    /**
     * Коды опций из payload, разложенные по категориям.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>
     */
    private function selectedCodes(array $payload): array
    {
        $selected = [];

        foreach (DoorOptionCategory::cases() as $category) {
            $value = $payload[$category->value] ?? $payload[$category->formField()] ?? null;

            if (blank($value)) {
                continue;
            }

            $selected[$category->value] = array_values(array_filter((array) $value));
        }

        return $selected;
    }

    /**
     * @param  array<string, list<string>>  $selected
     * @return array<string, string|list<string>>
     */
    private function flattenSelected(array $selected): array
    {
        return array_map(
            fn (array $codes) => count($codes) === 1 ? $codes[0] : $codes,
            $selected,
        );
    }

    /**
     * Одним запросом достаём все выбранные позиции прайса.
     *
     * @param  array<string, list<string>>  $selected
     * @return Collection<int, DoorOption>
     */
    private function resolveOptions(array $selected): Collection
    {
        if ($selected === []) {
            return collect();
        }

        return DoorOption::query()
            ->with('materialStock')
            ->active()
            ->where(function ($query) use ($selected): void {
                foreach ($selected as $category => $codes) {
                    $query->orWhere(fn ($q) => $q->where('category', $category)->whereIn('code', $codes));
                }
            })
            ->orderBy('sort')
            ->get();
    }

    private function materialCost(DoorOption $option, float $area, float $perimeter): float
    {
        if (! $option->materialStock || (float) $option->consumption <= 0.0) {
            return 0.0;
        }

        return round(
            $option->consumptionFor($area, $perimeter) * (float) $option->materialStock->price_per_unit,
            2,
        );
    }

    /** Сдельная оплата всех активных этапов цеха — трудозатраты на одно изделие. */
    private function laborCost(): float
    {
        return (float) FactoryStage::query()
            ->ofPipeline(PipelineType::Factory)
            ->active()
            ->sum('operation_cost');
    }

    private function round(float $value): float
    {
        $step = (float) config('gravit.pricing.round_to');

        return $step > 0.0 ? round($value / $step) * $step : round($value, 2);
    }
}
