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

/**
 * Позиция прайс-листа конфигуратора.
 *
 * @property DoorOptionCategory $category
 * @property PriceType $price_type
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
