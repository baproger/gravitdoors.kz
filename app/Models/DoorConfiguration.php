<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DoorCategory;
use App\Enums\DoorModel;
use App\Enums\DoorOptionCategory;
use App\Enums\OpeningSide;
use App\Observers\DoorConfigurationObserver;
use Database\Factories\DoorConfigurationFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Спецификация двери. Габариты хранятся в миллиметрах — так их вводит замерщик;
 * перевод в м² и погонные метры делается здесь, чтобы калькулятор и цех
 * считали площадь одинаково.
 *
 * @property int $id
 * @property int $deal_id
 * @property int $position
 * @property int $height
 * @property int $width
 * @property OpeningSide $opening_side
 * @property string|null $metal_thickness
 * @property string|null $outer_mdf_panel
 * @property string|null $inner_mdf_panel
 * @property string|null $lock_system
 * @property string|null $insulation_type
 * @property string|null $color_coating
 * @property array<array-key, mixed>|null $additional_options
 * @property int $quantity
 * @property numeric $calculated_price
 * @property array<array-key, mixed>|null $price_breakdown
 * @property string|null $comment
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property DoorCategory $category
 * @property DoorModel|null $model
 * @property-read Deal|null $deal
 *
 * @method static \Database\Factories\DoorConfigurationFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DoorConfiguration newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DoorConfiguration newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DoorConfiguration query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DoorConfiguration whereAdditionalOptions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DoorConfiguration whereCalculatedPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DoorConfiguration whereCategory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DoorConfiguration whereColorCoating($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DoorConfiguration whereComment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DoorConfiguration whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DoorConfiguration whereDealId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DoorConfiguration whereHeight($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DoorConfiguration whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DoorConfiguration whereInnerMdfPanel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DoorConfiguration whereInsulationType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DoorConfiguration whereLockSystem($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DoorConfiguration whereMetalThickness($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DoorConfiguration whereModel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DoorConfiguration whereOpeningSide($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DoorConfiguration whereOuterMdfPanel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DoorConfiguration wherePosition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DoorConfiguration wherePriceBreakdown($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DoorConfiguration whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DoorConfiguration whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DoorConfiguration whereWidth($value)
 *
 * @mixin \Eloquent
 */
#[ObservedBy(DoorConfigurationObserver::class)]
class DoorConfiguration extends Model
{
    /** @use HasFactory<DoorConfigurationFactory> */
    use HasFactory;

    protected $fillable = [
        'deal_id', 'position', 'category', 'model', 'height', 'width', 'opening_side', 'metal_thickness',
        'outer_mdf_panel', 'inner_mdf_panel', 'lock_system', 'insulation_type',
        'color_coating', 'additional_options', 'quantity',
        'calculated_price', 'price_breakdown', 'comment',
    ];

    protected function casts(): array
    {
        return [
            'opening_side' => OpeningSide::class,
            'category' => DoorCategory::class,
            'model' => DoorModel::class,
            'position' => 'integer',
            'height' => 'integer',
            'width' => 'integer',
            'quantity' => 'integer',
            'additional_options' => 'array',
            'price_breakdown' => 'array',
            'calculated_price' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Deal, $this> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** Площадь полотна, м². */
    public function areaSqm(): float
    {
        return round($this->height * $this->width / 1_000_000, 4);
    }

    /** Периметр коробки, м.п. */
    public function perimeterMeters(): float
    {
        return round(2 * ($this->height + $this->width) / 1000, 3);
    }

    /**
     * Выбранные коды опций по категориям — то, что уходит в калькулятор.
     *
     * @return array<string, list<string>>
     */
    public function selectedCodes(): array
    {
        $selected = [];

        foreach (DoorOptionCategory::cases() as $category) {
            $column = $category->configurationColumn();

            if ($column === null) {
                continue;
            }

            if (filled($this->{$column})) {
                $selected[$category->value] = [$this->{$column}];
            }
        }

        $additional = array_filter((array) $this->additional_options);

        if ($additional !== []) {
            $selected[DoorOptionCategory::Additional->value] = array_values($additional);
        }

        return $selected;
    }

    public function humanSize(): string
    {
        return "{$this->height} × {$this->width} мм";
    }

    /** Как позиция называется в списках: «Позиция 2 · Premium Лион». */
    public function displayName(): string
    {
        $product = trim(($this->category?->getLabel() ?? '').' '.($this->model?->getLabel() ?? ''));

        return $product !== ''
            ? "Позиция {$this->position} · {$product}"
            : "Позиция {$this->position}";
    }

    /** Название изделия без номера позиции — для наряда и страницы клиента. */
    public function productName(): string
    {
        return trim(($this->category?->getLabel() ?? '').' '.($this->model?->getLabel() ?? '')) ?: 'Дверь';
    }
}
