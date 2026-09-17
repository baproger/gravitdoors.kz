<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Платёж по долгу. Создаётся только через CashLedger::payDebt(): там же
 * рождается расход и списание со счёта.
 *
 * @property int $id
 * @property int $debt_id
 * @property int|null $expense_id
 * @property numeric $amount
 * @property Carbon $paid_at
 * @property PaymentMethod $method
 * @property int|null $account_id
 * @property string|null $receipt_path
 * @property int|null $user_id
 * @property-read Debt $debt
 * @property-read Expense|null $expense
 *
 * @method static Builder<static>|DebtPayment query()
 */
class DebtPayment extends Model
{
    protected $fillable = ['debt_id', 'expense_id', 'amount', 'paid_at', 'method', 'account_id', 'receipt_path', 'user_id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'date',
            'method' => PaymentMethod::class,
        ];
    }

    /** @return BelongsTo<Debt, $this> */
    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }

    /** @return BelongsTo<Expense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
