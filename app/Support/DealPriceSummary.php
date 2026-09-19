<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Итог по всей сделке: суммы всех позиций плюс то, что считается на заказ целиком.
 *
 * Сдельная оплата цеха — именно такая величина: наряд проходит этап один раз,
 * сколько бы дверей в нём ни было, и оплата за этап выплачивается один раз.
 * Поэтому труд прибавляется здесь, а не в каждой позиции: иначе заказ на три
 * двери показывал бы тройную себестоимость труда при одинарной выплате цеху.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class DealPriceSummary implements Arrayable
{
    /** @param list<PriceBreakdown> $positions */
    public function __construct(
        public array $positions,
        public float $total,
        public float $materialsCost,
        public float $laborCost,
    ) {}

    /**
     * @param  list<PriceBreakdown>  $positions
     * @param  float  $laborCost  сдельная оплата всех этапов цеха — один раз на заказ
     */
    public static function of(array $positions, float $laborCost = 0.0): self
    {
        return new self(
            positions: $positions,
            total: round(array_sum(array_map(fn (PriceBreakdown $b): float => $b->total, $positions)), 2),
            materialsCost: round(array_sum(array_map(fn (PriceBreakdown $b): float => $b->materialsCost, $positions)), 2),
            laborCost: round($laborCost, 2),
        );
    }

    /** Себестоимость заказа: материалы всех позиций плюс труд цеха один раз. */
    public function estimatedCost(): float
    {
        return round($this->materialsCost + $this->laborCost, 2);
    }

    public function doorsCount(): int
    {
        return array_sum(array_map(fn (PriceBreakdown $b): int => $b->quantity, $this->positions));
    }

    public function profit(): float
    {
        return round($this->total - $this->estimatedCost(), 2);
    }

    public function marginPercent(): float
    {
        return $this->total > 0.0 ? round($this->profit() / $this->total * 100, 2) : 0.0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'positions' => array_map(fn (PriceBreakdown $b): array => $b->toArray(), $this->positions),
            'doors_count' => $this->doorsCount(),
            'total' => $this->total,
            'materials_cost' => $this->materialsCost,
            'labor_cost' => $this->laborCost,
            'estimated_cost' => $this->estimatedCost(),
            'profit' => $this->profit(),
            'margin_percent' => $this->marginPercent(),
        ];
    }
}
