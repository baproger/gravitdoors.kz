<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Observers\DealPaymentObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Платёж клиента по сделке с чеком.
 *
 * @property int $id
 * @property int $deal_id
 * @property int|null $user_id
 * @property numeric $amount
 * @property PaymentMethod $method
 * @property int|null $account_id
 * @property Carbon $paid_at
 * @property-read CashAccount|null $account
 * @property string $receipt_path
 * @property string|null $comment
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Deal|null $deal
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DealPayment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DealPayment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DealPayment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DealPayment whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DealPayment whereComment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DealPayment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DealPayment whereDealId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DealPayment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DealPayment whereMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DealPayment wherePaidAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DealPayment whereReceiptPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DealPayment whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DealPayment whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[ObservedBy(DealPaymentObserver::class)]
class DealPayment extends Model
{
    protected $fillable = ['deal_id', 'user_id', 'amount', 'method', 'account_id', 'paid_at', 'receipt_path', 'comment'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'method' => PaymentMethod::class,
            'paid_at' => 'date',
        ];
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

    /** @return BelongsTo<CashAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'account_id');
    }

    /** @return MorphOne<CashMovement, $this> */
    public function cashMovement(): MorphOne
    {
        return $this->morphOne(CashMovement::class, 'source');
    }

    public function receiptUrl(): ?string
    {
        return $this->receipt_path ? Storage::disk('public')->url($this->receipt_path) : null;
    }
}
