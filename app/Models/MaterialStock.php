<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaterialUnit;
use Database\Factories\MaterialStockFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Позиция склада материалов.
 *
 * @property int $id
 * @property string|null $sku
 * @property string $name
 * @property MaterialUnit $unit
 * @property numeric $quantity
 * @property numeric $min_limit
 * @property numeric $price_per_unit
 * @property string|null $supplier
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, DoorOption> $doorOptions
 * @property-read int|null $door_options_count
 * @property-read Collection<int, StockMovement> $movements
 * @property-read int|null $movements_count
 *
 * @method static Builder<static>|MaterialStock belowLimit()
 * @method static \Database\Factories\MaterialStockFactory factory($count = null, $state = [])
 * @method static Builder<static>|MaterialStock newModelQuery()
 * @method static Builder<static>|MaterialStock newQuery()
 * @method static Builder<static>|MaterialStock query()
 * @method static Builder<static>|MaterialStock whereCreatedAt($value)
 * @method static Builder<static>|MaterialStock whereId($value)
 * @method static Builder<static>|MaterialStock whereIsActive($value)
 * @method static Builder<static>|MaterialStock whereMinLimit($value)
 * @method static Builder<static>|MaterialStock whereName($value)
 * @method static Builder<static>|MaterialStock wherePricePerUnit($value)
 * @method static Builder<static>|MaterialStock whereQuantity($value)
 * @method static Builder<static>|MaterialStock whereSku($value)
 * @method static Builder<static>|MaterialStock whereSupplier($value)
 * @method static Builder<static>|MaterialStock whereUnit($value)
 * @method static Builder<static>|MaterialStock whereUpdatedAt($value)
 *
 * @mixin \Eloquent
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
    /**
     * Материал с движениями или позициями прайса не удаляется: с ним ушла бы
     * история списаний, и отмена наряда не смогла бы ничего вернуть.
     */
    public function canBeDeleted(): bool
    {
        return $this->movements()->doesntExist() && $this->doorOptions()->doesntExist();
    }

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
