<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $material_stock_id
 * @property int|null $deal_id
 * @property int|null $user_id
 * @property string $type
 * @property numeric $quantity
 * @property numeric $price_per_unit
 * @property string|null $comment
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Deal|null $deal
 * @property-read MaterialStock $materialStock
 * @property-read User|null $user
 *
 * @method static \Database\Factories\StockMovementFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement whereComment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement whereDealId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement whereMaterialStockId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement wherePricePerUnit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement whereUserId($value)
 *
 * @mixin \Eloquent
 */
class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory;

    public const TYPE_IN = 'in';

    public const TYPE_OUT = 'out';

    protected $fillable = [
        'material_stock_id', 'deal_id', 'user_id', 'type', 'quantity', 'price_per_unit', 'comment',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'price_per_unit' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<MaterialStock, $this> */
    public function materialStock(): BelongsTo
    {
        return $this->belongsTo(MaterialStock::class);
    }

    /** @return BelongsTo<Deal, $this> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function total(): float
    {
        return round((float) $this->quantity * (float) $this->price_per_unit, 2);
    }
}
