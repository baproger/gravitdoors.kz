<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Services\CashLedger;
use Illuminate\Validation\ValidationException;

/**
 * Правила расхода на сервере: форма — не единственный путь в базу.
 */
class ExpenseObserver
{
    public function creating(Expense $expense): void
    {
        $expense->user_id ??= auth()->id();
        $expense->status ??= ExpenseStatus::Pending;
    }

    public function saving(Expense $expense): void
    {
        if ((float) $expense->amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Сумма расхода должна быть больше нуля.']);
        }

        if ($expense->spent_at->isFuture()) {
            throw ValidationException::withMessages(['spent_at' => 'Дата расхода не может быть в будущем.']);
        }

        // Чек нужен там, где его выдают. Проверяется при подтверждении, а не при
        // вводе: менеджер может завести расход вечером, а чек догрузить утром.
        if ($expense->status === ExpenseStatus::Approved
            && $expense->category->requiresReceipt()
            && blank($expense->receipt_path)) {
            throw ValidationException::withMessages(['receipt_path' => 'Без чека расход «'.$expense->category->getLabel().'» не подтверждается.']);
        }

        if ($expense->status === ExpenseStatus::Approved && $expense->approved_at === null) {
            $expense->approved_at = now();
            $expense->approved_by ??= auth()->id();
        }

        if ($expense->status !== ExpenseStatus::Approved) {
            $expense->approved_at = null;
            $expense->approved_by = null;
        }
    }

    /** Подтверждённый расход — списание со счёта; отклонённый или вернувшийся на проверку — снимается. */
    public function saved(Expense $expense): void
    {
        app(CashLedger::class)->syncExpense($expense);
    }

    public function deleting(Expense $expense): bool
    {
        return $expense->canBeDeleted();
    }

    public function deleted(Expense $expense): void
    {
        app(CashLedger::class)->forget($expense);
    }
}
