<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
