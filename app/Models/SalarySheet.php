<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SalarySheetStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Зарплатная ведомость сотрудника за месяц.
 *
 * @property int $id
 * @property int $user_id
 * @property string $month
 * @property numeric $salary
 * @property numeric $piecework
 * @property numeric $bonuses
 * @property numeric $deductions
 * @property numeric $advances
 * @property numeric $total
 * @property numeric $paid_amount
 * @property SalarySheetStatus $status
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $comment
 * @property-read User $user
 *
 * @method static Builder<static>|SalarySheet query()
 */
class SalarySheet extends Model
{
    protected $fillable = ['user_id', 'month', 'salary', 'piecework', 'bonuses', 'deductions', 'advances', 'total', 'paid_amount', 'status', 'approved_by', 'approved_at', 'comment'];

    protected function casts(): array
    {
        return [
            'salary' => 'decimal:2',
            'piecework' => 'decimal:2',
            'bonuses' => 'decimal:2',
            'deductions' => 'decimal:2',
            'advances' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'status' => SalarySheetStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<SalaryPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SalaryPayment::class, 'sheet_id');
    }

    public function remaining(): float
    {
        return max(0.0, round((float) $this->total - (float) $this->paid_amount, 2));
    }

    public function isDraft(): bool
    {
        return $this->status === SalarySheetStatus::Draft;
    }

    /** Итог к выплате: оклад + сдельно + бонусы − удержания − авансы. */
    public static function totalOf(float $salary, float $piecework, float $bonuses, float $deductions, float $advances): float
    {
        return max(0.0, round($salary + $piecework + $bonuses - $deductions - $advances, 2));
    }
}
