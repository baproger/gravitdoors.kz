<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Результат расчёта двери: не только сумма, но и её расшифровка.
 * Расшифровка сохраняется в door_configurations.price_breakdown, поэтому
 * последующая правка прайса не переписывает историю уже посчитанных заказов.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class PriceBreakdown implements Arrayable
{
    /**
     * @param  list<array{category: string, code: string, label: string, price_type: string, unit_price: float, multiplier: float, amount: float}>  $lines
     */
    public function __construct(
        public array $lines,
        public float $areaSqm,
        public float $perimeterMeters,
        public int $quantity,
        public float $optionsTotal,
        public float $assemblyCost,
        public float $markupAmount,
        public float $unitPrice,
        public float $total,
        public float $estimatedCost,
    ) {}

    public function profit(): float
    {
        return round($this->total - $this->estimatedCost, 2);
    }

    public function marginPercent(): float
    {
        return $this->total > 0.0 ? round($this->profit() / $this->total * 100, 2) : 0.0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'lines' => $this->lines,
            'area_sqm' => $this->areaSqm,
            'perimeter_m' => $this->perimeterMeters,
            'quantity' => $this->quantity,
            'options_total' => $this->optionsTotal,
            'assembly_cost' => $this->assemblyCost,
            'markup_amount' => $this->markupAmount,
            'unit_price' => $this->unitPrice,
            'total' => $this->total,
            'estimated_cost' => $this->estimatedCost,
            'profit' => $this->profit(),
            'margin_percent' => $this->marginPercent(),
            'calculated_at' => now()->toDateTimeString(),
        ];
    }
}
