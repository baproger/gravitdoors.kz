<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Выплата по ведомости. Создаётся только через PayrollService::pay().
 *
 * @property int $id
 * @property int $sheet_id
 * @property int|null $expense_id
 * @property numeric $amount
 * @property Carbon $paid_at
 * @property PaymentMethod $method
 * @property int|null $account_id
 * @property int|null $user_id
 *
 * @method static Builder<static>|SalaryPayment query()
 */
class SalaryPayment extends Model
{
    protected $fillable = ['sheet_id', 'expense_id', 'amount', 'paid_at', 'method', 'account_id', 'user_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'date', 'method' => PaymentMethod::class];
    }

    /** @return BelongsTo<SalarySheet, $this> */
    public function sheet(): BelongsTo
    {
        return $this->belongsTo(SalarySheet::class, 'sheet_id');
    }

    /** @return BelongsTo<Expense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
