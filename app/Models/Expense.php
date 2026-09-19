<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Observers\ExpenseObserver;
use App\Support\Uploads\PrivateFiles;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Расход компании. В сводку попадает только подтверждённый (approved).
 *
 * @property int $id
 * @property ExpenseCategory $category
 * @property numeric $amount
 * @property Carbon $spent_at
 * @property PaymentMethod $method
 * @property int|null $account_id
 * @property string|null $counterparty
 * @property string|null $comment
 * @property string|null $receipt_path
 * @property int|null $deal_id
 * @property int|null $user_id
 * @property ExpenseStatus $status
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $rejection_reason
 * @property-read User|null $user
 * @property-read User|null $approver
 * @property-read Deal|null $deal
 *
 * @method static Builder<static>|Expense approved()
 * @method static Builder<static>|Expense pending()
 * @method static Builder<static>|Expense query()
 */
#[ObservedBy(ExpenseObserver::class)]
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'category', 'amount', 'spent_at', 'method', 'account_id', 'counterparty', 'comment',
        'receipt_path', 'deal_id', 'user_id', 'status', 'approved_by', 'approved_at', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'amount' => 'decimal:2',
            'spent_at' => 'date',
            'method' => PaymentMethod::class,
            'status' => ExpenseStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return MorphOne<CashMovement, $this> */
    public function cashMovement(): MorphOne
    {
        return $this->morphOne(CashMovement::class, 'source');
    }

    /** @return BelongsTo<Deal, $this> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @param Builder<Expense> $query */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', ExpenseStatus::Approved->value);
    }

    /** @param Builder<Expense> $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ExpenseStatus::Pending->value);
    }

    public function isApproved(): bool
    {
        return $this->status === ExpenseStatus::Approved;
    }

    /**
     * Подтверждённый расход — уже деньги в отчёте: его не удаляют, а отклоняют
     * с причиной. Проверяется в модели, потому что администратор проходит любую политику.
     */
    public function canBeDeleted(): bool
    {
        return ! $this->isApproved();
    }

    public function receiptUrl(): ?string
    {
        return PrivateFiles::url($this->receipt_path);
    }
}
