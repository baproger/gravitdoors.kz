<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\DebtStatus;
use App\Models\Debt;
use Illuminate\Validation\ValidationException;

class DebtObserver
{
    public function creating(Debt $debt): void
    {
        $debt->user_id ??= auth()->id();
        $debt->status ??= DebtStatus::Open;
    }

    public function saving(Debt $debt): void
    {
        if ((float) $debt->amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Сумма долга должна быть больше нуля.']);
        }

        // Уменьшить долг ниже уже выплаченного нельзя — платежи реальные.
        if ((float) $debt->amount < (float) $debt->paid_amount) {
            throw ValidationException::withMessages(['amount' => 'Сумма долга меньше уже выплаченного ('.(float) $debt->paid_amount.').']);
        }

        // Статус «погашен» ставится только по факту: остаток ноль.
        if ($debt->status === DebtStatus::Paid && $debt->remaining() > 0) {
            throw ValidationException::withMessages(['status' => 'Долг с остатком нельзя отметить погашенным — внесите платёж.']);
        }

        if ($debt->status === DebtStatus::Open && $debt->remaining() <= 0 && (float) $debt->paid_amount > 0) {
            $debt->status = DebtStatus::Paid;
        }
    }

    public function deleting(Debt $debt): bool
    {
        return $debt->canBeDeleted();
    }
}
