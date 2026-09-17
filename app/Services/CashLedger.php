<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\DealPayment;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Журнал денег. Единственное место, где создаются движения по счетам:
 * платёж по сделке — приход, подтверждённый расход — списание, перевод —
 * пара движений, корректировка — ручная запись администратора с причиной.
 *
 * Синхронизация по источнику идемпотентна: повторный вызов обновляет одно
 * движение, а не добавляет второе.
 */
class CashLedger
{
    /** Платёж по сделке: одно движение «приход» на счёт по способу оплаты. */
    public function syncPayment(DealPayment $payment): ?CashMovement
    {
        $account = $this->accountFor($payment->account_id, $payment->method);

        if (! $account) {
            return null;
        }

        return $this->upsert($payment, $account, CashMovement::IN, (float) $payment->amount, $payment->paid_at,
            "Оплата по сделке {$payment->deal?->number}", $payment->user_id);
    }

    /** Подтверждённый расход — списание; любой другой статус движение снимает. */
    public function syncExpense(Expense $expense): ?CashMovement
    {
        if (! $expense->isApproved()) {
            $this->forget($expense);

            return null;
        }

        $account = $this->accountFor($expense->account_id, $expense->method);

        if (! $account) {
            return null;
        }

        return $this->upsert($expense, $account, CashMovement::OUT, (float) $expense->amount, $expense->spent_at,
            trim($expense->category->getLabel().($expense->counterparty ? ' · '.$expense->counterparty : '')), $expense->user_id);
    }

    /**
     * Платёж по долгу: расход «оплачено» (он же спишет деньги со счёта) + запись
     * платежа + пересчёт остатка долга. Всё в одной транзакции.
     */
    public function payDebt(Debt $debt, float $amount, PaymentMethod $method, \DateTimeInterface $at, ?int $accountId, ?string $receiptPath, ?User $actor): DebtPayment
    {
        if ($debt->status->isClosed()) {
            throw ValidationException::withMessages(['debt' => 'Долг уже закрыт.']);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Сумма платежа должна быть больше нуля.']);
        }

        if (round($amount, 2) > $debt->remaining()) {
            throw ValidationException::withMessages(['amount' => 'Платёж больше остатка долга ('.$debt->remaining().').']);
        }

        return DB::transaction(function () use ($debt, $amount, $method, $at, $accountId, $receiptPath, $actor): DebtPayment {
            $expense = Expense::create([
                'category' => $debt->category->expenseCategory()->value,
                'amount' => $amount,
                'spent_at' => $at,
                'method' => $method->value,
                'account_id' => $accountId,
                'counterparty' => $debt->counterparty,
                'comment' => "Платёж по долгу #{$debt->id}".($debt->comment ? ': '.$debt->comment : ''),
                'receipt_path' => $receiptPath,
                'user_id' => $actor?->id,
                'status' => ExpenseStatus::Approved->value,
                'approved_by' => $actor?->id,
                'approved_at' => now(),
            ]);

            $payment = DebtPayment::create([
                'debt_id' => $debt->id,
                'expense_id' => $expense->id,
                'amount' => $amount,
                'paid_at' => $at,
                'method' => $method->value,
                'account_id' => $accountId,
                'receipt_path' => $receiptPath,
                'user_id' => $actor?->id,
            ]);

            $debt->forceFill(['paid_amount' => round((float) $debt->payments()->sum('amount'), 2)])->save();

            return $payment;
        });
    }

    /** Убрать движение источника (источник удалён или отклонён). */
    public function forget(Model $source): void
    {
        CashMovement::query()
            ->where('source_type', $source::class)
            ->where('source_id', $source->getKey())
            ->delete();
    }

    /** Перевод между счетами: из кассы в банк (инкассация) или обратно. */
    public function transfer(CashAccount $from, CashAccount $to, float $amount, \DateTimeInterface $at, ?string $comment, ?User $actor): string
    {
        if ($from->is($to)) {
            throw ValidationException::withMessages(['to' => 'Счёт списания и зачисления совпадают.']);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Сумма перевода должна быть больше нуля.']);
        }

        $id = (string) Str::uuid();
        $comment = $comment ?: "Перевод {$from->name} → {$to->name}";

        DB::transaction(function () use ($from, $to, $amount, $at, $comment, $actor, $id): void {
            CashMovement::create(['account_id' => $from->id, 'direction' => CashMovement::OUT, 'amount' => $amount, 'happened_at' => $at, 'transfer_id' => $id, 'comment' => $comment, 'user_id' => $actor?->id]);
            CashMovement::create(['account_id' => $to->id, 'direction' => CashMovement::IN, 'amount' => $amount, 'happened_at' => $at, 'transfer_id' => $id, 'comment' => $comment, 'user_id' => $actor?->id]);
        });

        return $id;
    }

    /** Корректировка после инвентаризации: знак суммы задаёт направление, причина обязательна. */
    public function adjust(CashAccount $account, float $amount, \DateTimeInterface $at, string $reason, ?User $actor): CashMovement
    {
        if ($amount == 0.0) {
            throw ValidationException::withMessages(['amount' => 'Корректировка на ноль не имеет смысла.']);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Укажите причину корректировки.']);
        }

        return CashMovement::create([
            'account_id' => $account->id,
            'direction' => $amount > 0 ? CashMovement::IN : CashMovement::OUT,
            'amount' => abs($amount),
            'happened_at' => $at,
            'comment' => 'Корректировка: '.trim($reason),
            'user_id' => $actor?->id,
        ]);
    }

    /**
     * Разнести по журналу платежи и расходы, внесённые до появления кассы.
     * Идемпотентно: у кого движение уже есть — пропускается.
     *
     * @return array{payments: int, expenses: int}
     */
    public function backfill(): array
    {
        $payments = 0;
        $expenses = 0;

        DealPayment::query()->with('deal')->whereDoesntHave('cashMovement')->each(function (DealPayment $payment) use (&$payments): void {
            if ($this->syncPayment($payment)) {
                $payments++;
            }
        });

        Expense::query()->approved()->whereDoesntHave('cashMovement')->each(function (Expense $expense) use (&$expenses): void {
            if ($this->syncExpense($expense)) {
                $expenses++;
            }
        });

        return ['payments' => $payments, 'expenses' => $expenses];
    }

    private function accountFor(?int $accountId, PaymentMethod $method): ?CashAccount
    {
        return ($accountId ? CashAccount::query()->find($accountId) : null) ?? CashAccount::defaultFor($method);
    }

    private function upsert(Model $source, CashAccount $account, string $direction, float $amount, \DateTimeInterface $at, string $comment, ?int $userId): CashMovement
    {
        return CashMovement::query()->updateOrCreate(
            ['source_type' => $source::class, 'source_id' => $source->getKey()],
            [
                'account_id' => $account->id,
                'direction' => $direction,
                'amount' => $amount,
                'happened_at' => $at,
                'comment' => $comment,
                'user_id' => $userId,
            ],
        );
    }
}
