<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BonusStatus;
use App\Observers\BonusObserver;
use Database\Factories\BonusFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Бонус сотруднику за месяц. В ведомость попадает только утверждённый.
 *
 * @property int $id
 * @property int $user_id
 * @property string $month
 * @property numeric $amount
 * @property string $reason
 * @property int|null $deal_id
 * @property int|null $created_by
 * @property BonusStatus $status
 * @property string $source
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property-read User $user
 * @property-read Deal|null $deal
 *
 * @method static Builder<static>|Bonus query()
 */
#[ObservedBy(BonusObserver::class)]
class Bonus extends Model
{
    /** @use HasFactory<BonusFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = ['user_id', 'month', 'amount', 'reason', 'deal_id', 'created_by', 'status', 'source', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => BonusStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Deal, $this> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param Builder<Bonus> $query */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', BonusStatus::Approved->value);
    }

    /** @param Builder<Bonus> $query */
    public function scopeForMonth(Builder $query, string $month): void
    {
        $query->where('month', $month);
    }

    public function isApproved(): bool
    {
        return $this->status === BonusStatus::Approved;
    }

    /** Утверждённый бонус уже в ведомости — не удаляется, а снимается с утверждения. */
    public function canBeDeleted(): bool
    {
        return ! $this->isApproved();
    }
}
