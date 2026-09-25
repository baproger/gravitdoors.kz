<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DoorCategory;
use App\Enums\DoorModel;
use App\Enums\TenderLotResult;
use App\Services\TenderService;
use Database\Factories\TenderLotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Лот тендера: одна позиция закупки — изделие, размер и количество.
 *
 * Характеристики (металл, замок, МДФ) здесь не выбираются: в заявке важны
 * количество и цена, а спецификацию B2B доводит уже в сделке. Поэтому цена по
 * прайсу и себестоимость считаются по позициям прайса «по умолчанию».
 *
 * @property int $id
 * @property int $tender_id
 * @property string|null $lot_number
 * @property string $name
 * @property DoorCategory $category
 * @property DoorModel $model
 * @property int $height
 * @property int $width
 * @property int $quantity
 * @property numeric|null $budget_unit_price
 * @property numeric|null $bid_unit_price
 * @property numeric|null $list_unit_price
 * @property numeric|null $estimated_cost
 * @property TenderLotResult $result
 * @property string|null $winner_name
 * @property numeric|null $winner_unit_price
 * @property int|null $deal_id
 * @property string|null $comment
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tender $tender
 * @property-read Deal|null $deal
 */
class TenderLot extends Model
{
    /** @use HasFactory<TenderLotFactory> */
    use HasFactory;

    /** Потолок количества в лоте — от опечатки «10000» вместо «100». */
    public const MAX_QUANTITY = 5000;

    protected $fillable = [
        'tender_id', 'lot_number', 'name', 'category', 'model', 'height', 'width', 'quantity',
        'budget_unit_price', 'bid_unit_price', 'result', 'winner_name', 'winner_unit_price', 'comment',
    ];

    protected function casts(): array
    {
        return [
            'category' => DoorCategory::class,
            'model' => DoorModel::class,
            'result' => TenderLotResult::class,
            'height' => 'integer',
            'width' => 'integer',
            'quantity' => 'integer',
            'budget_unit_price' => 'decimal:2',
            'bid_unit_price' => 'decimal:2',
            'list_unit_price' => 'decimal:2',
            'estimated_cost' => 'decimal:2',
            'winner_unit_price' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // Прайс и себестоимость — снимок на момент правки лота: по ним решают,
        // какую цену подавать, и правка прайса потом не должна их переписывать.
        static::saving(function (TenderLot $lot): void {
            if ($lot->isDirty(['category', 'model', 'height', 'width', 'quantity']) || $lot->list_unit_price === null) {
                app(TenderService::class)->estimate($lot);
            }
        });
    }

    /** @return BelongsTo<Tender, $this> */
    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    /** @return BelongsTo<Deal, $this> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function bidTotal(): float
    {
        return round((float) $this->bid_unit_price * $this->quantity, 2);
    }

    public function budgetTotal(): float
    {
        return round((float) $this->budget_unit_price * $this->quantity, 2);
    }

    /** Маржа нашей цены против себестоимости, %; null — цены ещё нет. */
    public function marginPercent(): ?float
    {
        $bid = $this->bidTotal();

        if ($bid <= 0.0 || $this->estimated_cost === null) {
            return null;
        }

        return round(($bid - (float) $this->estimated_cost) / $bid * 100, 1);
    }

    /** Наша цена выше потолка заказчика — заявку отклонят. */
    public function isOverBudget(): bool
    {
        return $this->budget_unit_price !== null
            && $this->bid_unit_price !== null
            && (float) $this->bid_unit_price > (float) $this->budget_unit_price;
    }

    public function hasDeal(): bool
    {
        return $this->deal_id !== null;
    }

    public function displayName(): string
    {
        return filled($this->lot_number) ? "Лот {$this->lot_number} · {$this->name}" : $this->name;
    }
}
