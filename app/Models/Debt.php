<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DebtCategory;
use App\Enums\DebtStatus;
use App\Observers\DebtObserver;
use Database\Factories\DebtFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Долг компании перед контрагентом.
 *
 * @property int $id
 * @property string $counterparty
 * @property DebtCategory $category
 * @property numeric $amount
 * @property numeric $paid_amount
 * @property Carbon|null $due_at
 * @property string|null $comment
 * @property string|null $document_path
 * @property DebtStatus $status
 * @property int|null $user_id
 *
 * @method static Builder<static>|Debt query()
 */
#[ObservedBy(DebtObserver::class)]
class Debt extends Model
{
    /** @use HasFactory<DebtFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = ['counterparty', 'category', 'amount', 'paid_amount', 'due_at', 'comment', 'document_path', 'status', 'user_id'];

    protected function casts(): array
    {
        return [
            'category' => DebtCategory::class,
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'due_at' => 'date',
            'status' => DebtStatus::class,
        ];
    }

    /** @return HasMany<DebtPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(DebtPayment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<Debt> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', DebtStatus::Open->value);
    }

    public function remaining(): float
    {
        return max(0.0, round((float) $this->amount - (float) $this->paid_amount, 2));
    }

    public function isOverdue(): bool
    {
        return $this->status === DebtStatus::Open && $this->due_at !== null && $this->due_at->lt(today());
    }

    public function overdueDays(): int
    {
        return $this->isOverdue() ? (int) $this->due_at->diffInDays(today()) : 0;
    }

    /** Долг с платежами не удаляется — по нему уже есть расходы и движения денег. */
    public function canBeDeleted(): bool
    {
        return $this->payments()->doesntExist();
    }
}
