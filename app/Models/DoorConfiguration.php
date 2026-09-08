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

/**
 * Спецификация двери. Габариты хранятся в миллиметрах — так их вводит замерщик;
 * перевод в м² и погонные метры делается здесь, чтобы калькулятор и цех
 * считали площадь одинаково.
 *
 * @property OpeningSide $opening_side
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
