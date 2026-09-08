<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Итог по всей сделке: сумма расчётов всех позиций (дверей) заказа.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class DealPriceSummary implements Arrayable
{
    /** @param list<PriceBreakdown> $positions */
    public function __construct(
        public array $positions,
        public float $total,
        public float $estimatedCost,
    ) {}

    /** @param list<PriceBreakdown> $positions */
    public static function of(array $positions): self
    {
        return new self(
            positions: $positions,
            total: round(array_sum(array_map(fn (PriceBreakdown $b): float => $b->total, $positions)), 2),
            estimatedCost: round(array_sum(array_map(fn (PriceBreakdown $b): float => $b->estimatedCost, $positions)), 2),
        );
    }

    public function doorsCount(): int
    {
        return array_sum(array_map(fn (PriceBreakdown $b): int => $b->quantity, $this->positions));
    }

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
            'positions' => array_map(fn (PriceBreakdown $b): array => $b->toArray(), $this->positions),
            'doors_count' => $this->doorsCount(),
            'total' => $this->total,
            'estimated_cost' => $this->estimatedCost,
            'profit' => $this->profit(),
            'margin_percent' => $this->marginPercent(),
        ];
    }
}
