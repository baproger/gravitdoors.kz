<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DoorOptionCategory;
use App\Enums\PriceType;
use Database\Factories\DoorOptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Позиция прайс-листа конфигуратора.
 *
 * @property int $id
 * @property DoorOptionCategory $category
 * @property string $code
 * @property string $label
 * @property numeric $price
 * @property PriceType $price_type
 * @property int|null $material_stock_id
 * @property numeric $consumption
 * @property int $sort
 * @property bool $is_default
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MaterialStock|null $materialStock
 *
 * @method static Builder<static>|DoorOption active()
 * @method static \Database\Factories\DoorOptionFactory factory($count = null, $state = [])
 * @method static Builder<static>|DoorOption newModelQuery()
 * @method static Builder<static>|DoorOption newQuery()
 * @method static Builder<static>|DoorOption ofCategory(\App\Enums\DoorOptionCategory|string $category)
 * @method static Builder<static>|DoorOption query()
 * @method static Builder<static>|DoorOption whereCategory($value)
 * @method static Builder<static>|DoorOption whereCode($value)
 * @method static Builder<static>|DoorOption whereConsumption($value)
 * @method static Builder<static>|DoorOption whereCreatedAt($value)
 * @method static Builder<static>|DoorOption whereId($value)
 * @method static Builder<static>|DoorOption whereIsActive($value)
 * @method static Builder<static>|DoorOption whereIsDefault($value)
 * @method static Builder<static>|DoorOption whereLabel($value)
 * @method static Builder<static>|DoorOption whereMaterialStockId($value)
 * @method static Builder<static>|DoorOption wherePrice($value)
 * @method static Builder<static>|DoorOption wherePriceType($value)
 * @method static Builder<static>|DoorOption whereSort($value)
 * @method static Builder<static>|DoorOption whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class DoorOption extends Model
{
    /** @use HasFactory<DoorOptionFactory> */
    use HasFactory;

    protected $fillable = [
        'category', 'code', 'label', 'price', 'price_type',
        'material_stock_id', 'consumption', 'sort', 'is_default', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'category' => DoorOptionCategory::class,
            'price_type' => PriceType::class,
            'price' => 'decimal:2',
            'consumption' => 'decimal:3',
            'sort' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<MaterialStock, $this> */
    public function materialStock(): BelongsTo
    {
        return $this->belongsTo(MaterialStock::class);
    }

    /** @param Builder<DoorOption> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param Builder<DoorOption> $query */
    public function scopeOfCategory(Builder $query, DoorOptionCategory|string $category): void
    {
        $query->where('category', $category instanceof DoorOptionCategory ? $category->value : $category);
    }

    /**
     * Сколько стоит опция для конкретных габаритов.
     * Множитель — это и есть «габариты × металл» из ТЗ.
     */
    public function priceFor(float $areaSqm, float $perimeterMeters): float
    {
        return round((float) $this->price * $this->multiplier($areaSqm, $perimeterMeters), 2);
    }

    /** Расход материала на изделие в единицах склада. */
    public function consumptionFor(float $areaSqm, float $perimeterMeters): float
    {
        return round((float) $this->consumption * $this->multiplier($areaSqm, $perimeterMeters), 3);
    }

    private function multiplier(float $areaSqm, float $perimeterMeters): float
    {
        return match ($this->price_type) {
            PriceType::PerSquareMeter => $areaSqm,
            PriceType::PerMeter => $perimeterMeters,
            PriceType::Fixed => 1.0,
        };
    }
}
