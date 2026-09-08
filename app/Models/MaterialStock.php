<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaterialUnit;
use Database\Factories\MaterialStockFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Позиция склада материалов.
 *
 * @property MaterialUnit $unit
 */
class MaterialStock extends Model
{
    /** @use HasFactory<MaterialStockFactory> */
    use HasFactory;

    protected $fillable = [
        'sku', 'name', 'unit', 'quantity', 'min_limit', 'price_per_unit', 'supplier', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'unit' => MaterialUnit::class,
            'quantity' => 'decimal:3',
            'min_limit' => 'decimal:3',
            'price_per_unit' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<StockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** @return HasMany<DoorOption, $this> */
    public function doorOptions(): HasMany
    {
        return $this->hasMany(DoorOption::class);
    }

    /** @param Builder<MaterialStock> $query */
    public function scopeBelowLimit(Builder $query): void
    {
        $query->whereColumn('quantity', '<=', 'min_limit');
    }

    public function isBelowLimit(): bool
    {
        return (float) $this->quantity <= (float) $this->min_limit;
    }

    public function stockValue(): float
    {
        return round((float) $this->quantity * (float) $this->price_per_unit, 2);
    }
}
