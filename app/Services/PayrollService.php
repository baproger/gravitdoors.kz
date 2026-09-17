<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductionStatus;
use App\Enums\SalarySheetStatus;
use App\Models\Bonus;
use App\Models\Expense;
use App\Models\ProductionLog;
use App\Models\SalaryPayment;
use App\Models\SalarySheet;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Зарплатная ведомость: сформировать, утвердить, выплатить.
 *
 * Черновик пересчитывается из источников (оклад, закрытые этапы, утверждённые
 * бонусы) сколько угодно раз; после утверждения цифры зафиксированы —
 * повышение оклада не меняет уже утверждённый месяц.
 */
class PayrollService
{
    /**
     * Сформировать или пересчитать черновики за месяц по всем активным сотрудникам.
     * Утверждённые и выплаченные ведомости не трогаются.
     *
     * @return Collection<int, SalarySheet>
     */
    public function build(string $month): Collection
    {
        [$from, $to] = self::period($month);

        return DB::transaction(function () use ($month, $from, $to): Collection {
            $users = User::query()->where('is_active', true)->orderBy('name')->get();
            $sheets = collect();

            foreach ($users as $user) {
                $sheet = SalarySheet::query()->firstOrNew(['user_id' => $user->id, 'month' => $month]);

                if ($sheet->exists && ! $sheet->isDraft()) {
                    $sheets->push($sheet);

                    continue;
                }

                $salary = $this->proratedSalary($user, $from, $to);
                $piecework = round((float) ProductionLog::query()
                    ->where('worker_id', $user->id)
                    ->where('status', ProductionStatus::Done->value)
                    ->whereBetween('finished_at', [$from, $to])
                    ->sum('payout'), 2);
                $bonuses = round((float) Bonus::query()->approved()->forMonth($month)->where('user_id', $user->id)->sum('amount'), 2);

                // Пустую ведомость (нет ни оклада, ни выработки) не плодим.
                if ($salary <= 0 && $piecework <= 0 && $bonuses <= 0 && ! $sheet->exists) {
                    continue;
                }

                $sheet->fill([
                    'salary' => $salary,
                    'piecework' => $piecework,
                    'bonuses' => $bonuses,
                    'status' => SalarySheetStatus::Draft,
                ]);
                $sheet->total = SalarySheet::totalOf($salary, $piecework, $bonuses, (float) $sheet->deductions, (float) $sheet->advances);
                $sheet->save();

                $sheets->push($sheet);
            }

            return $sheets;
        });
    }

    /** Удержания и авансы правятся только в черновике; итог пересчитывается. */
    public function adjust(SalarySheet $sheet, float $deductions, float $advances, ?string $comment): SalarySheet
    {
        if (! $sheet->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Ведомость утверждена — удержания уже не меняются.']);
        }

        if ($deductions < 0 || $advances < 0) {
            throw ValidationException::withMessages(['deductions' => 'Удержания и авансы не бывают отрицательными.']);
        }

        $sheet->forceFill([
            'deductions' => round($deductions, 2),
            'advances' => round($advances, 2),
            'comment' => $comment,
            'total' => SalarySheet::totalOf((float) $sheet->salary, (float) $sheet->piecework, (float) $sheet->bonuses, $deductions, $advances),
        ])->save();

        return $sheet;
    }

    public function approve(SalarySheet $sheet, ?User $actor): SalarySheet
    {
        if (! $sheet->isDraft()) {
            return $sheet;
        }

        $sheet->forceFill([
            'status' => SalarySheetStatus::Approved,
            'approved_by' => $actor?->id,
            'approved_at' => now(),
        ])->save();

        return $sheet;
    }

    /** Выплата: расход «Зарплата» (оплачено) → списание со счёта → запись выплаты → остаток ведомости. */
    public function pay(SalarySheet $sheet, float $amount, PaymentMethod $method, \DateTimeInterface $at, ?int $accountId, ?User $actor): SalaryPayment
    {
        if ($sheet->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Сначала утвердите ведомость.']);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Сумма выплаты должна быть больше нуля.']);
        }

        if (round($amount, 2) > $sheet->remaining()) {
            throw ValidationException::withMessages(['amount' => 'Выплата больше остатка по ведомости ('.$sheet->remaining().').']);
        }

        return DB::transaction(function () use ($sheet, $amount, $method, $at, $accountId, $actor): SalaryPayment {
            $expense = Expense::create([
                'category' => ExpenseCategory::Salary->value,
                'amount' => $amount,
                'spent_at' => $at,
                'method' => $method->value,
                'account_id' => $accountId,
                'counterparty' => $sheet->user->name,
                'comment' => "Зарплата за {$sheet->month}",
                'user_id' => $actor?->id,
                'status' => ExpenseStatus::Approved->value,
                'approved_by' => $actor?->id,
                'approved_at' => now(),
            ]);

            $payment = SalaryPayment::create([
                'sheet_id' => $sheet->id,
                'expense_id' => $expense->id,
                'amount' => $amount,
                'paid_at' => $at,
                'method' => $method->value,
                'account_id' => $accountId,
                'user_id' => $actor?->id,
            ]);

            $paid = round((float) $sheet->payments()->sum('amount'), 2);

            $sheet->forceFill([
                'paid_amount' => $paid,
                'status' => $paid >= (float) $sheet->total ? SalarySheetStatus::Paid : SalarySheetStatus::Approved,
            ])->save();

            Notification::make()
                ->title(($sheet->status === SalarySheetStatus::Paid ? 'Зарплата выплачена: ' : 'Выплата: ').Money::format($amount))
                ->body("За {$sheet->month} · {$method->getLabel()}".($sheet->remaining() > 0 ? ' · остаток '.Money::format($sheet->remaining()) : ''))
                ->icon('heroicon-o-banknotes')
                ->success()
                ->sendToDatabase($sheet->user);

            return $payment;
        });
    }

    /** Оклад пропорционально дням, если сотрудник принят внутри месяца. */
    public function proratedSalary(User $user, CarbonImmutable $from, CarbonImmutable $to): float
    {
        $salary = (float) $user->salary;

        if ($salary <= 0) {
            return 0.0;
        }

        if ($user->hired_at === null || $user->hired_at->lte($from)) {
            return round($salary, 2);
        }

        if ($user->hired_at->gt($to)) {
            return 0.0;
        }

        $daysInMonth = $from->daysInMonth;
        $worked = (int) $user->hired_at->toImmutable()->startOfDay()->diffInDays($to->endOfDay()) + 1;

        return round($salary * min($worked, $daysInMonth) / $daysInMonth, 2);
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    public static function period(string $month): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();

        return [$start, $start->endOfMonth()];
    }
}
