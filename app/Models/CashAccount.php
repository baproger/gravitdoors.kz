<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CashAccountType;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Счёт денег: касса или банковский счёт.
 *
 * @property int $id
 * @property string $name
 * @property CashAccountType $type
 * @property numeric $opening_balance
 * @property Carbon|null $opening_at
 * @property bool $is_active
 * @property string|null $note
 *
 * @method static Builder<static>|CashAccount query()
 */
class CashAccount extends Model
{
    protected $fillable = ['name', 'type', 'opening_balance', 'opening_at', 'is_active', 'note'];

    protected function casts(): array
    {
        return [
            'type' => CashAccountType::class,
            'opening_balance' => 'decimal:2',
            'opening_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<CashMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class, 'account_id');
    }

    /** Остаток на дату (по умолчанию — сейчас): начальный + приход − расход. */
    public function balance(?\DateTimeInterface $at = null): float
    {
        $query = $this->movements();

        if ($at) {
            $query->whereDate('happened_at', '<=', $at);
        }

        $net = (float) $query
            ->selectRaw("SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) as net")
            ->value('net');

        return round((float) $this->opening_balance + $net, 2);
    }

    /** Счёт по умолчанию для способа оплаты: первый активный нужного типа. */
    public static function defaultFor(PaymentMethod $method): ?self
    {
        return static::query()
            ->where('type', CashAccountType::forMethod($method)->value)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }

    /** Счёт с движениями не удаляется — с ним ушла бы история денег. */
    public function canBeDeleted(): bool
    {
        return $this->movements()->doesntExist();
    }
}
