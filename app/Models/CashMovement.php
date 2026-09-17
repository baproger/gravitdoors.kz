<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Одно движение денег по счёту.
 *
 * @property int $id
 * @property int $account_id
 * @property string $direction
 * @property numeric $amount
 * @property Carbon $happened_at
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string|null $transfer_id
 * @property string|null $comment
 * @property int|null $user_id
 * @property-read CashAccount $account
 * @property-read User|null $user
 * @property-read Model|null $source
 *
 * @method static Builder<static>|CashMovement query()
 */
class CashMovement extends Model
{
    public const IN = 'in';

    public const OUT = 'out';

    protected $fillable = ['account_id', 'direction', 'amount', 'happened_at', 'source_type', 'source_id', 'transfer_id', 'comment', 'user_id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'happened_at' => 'date',
        ];
    }

    /** @return BelongsTo<CashAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function isIn(): bool
    {
        return $this->direction === self::IN;
    }

    /** Что это за движение — для журнала. */
    public function kind(): string
    {
        return match (true) {
            $this->transfer_id !== null => 'Перевод',
            $this->source_type === DealPayment::class => 'Оплата по сделке',
            $this->source_type === Expense::class => 'Расход',
            $this->source_type === null => 'Корректировка',
            default => 'Движение',
        };
    }
}
