<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CashAccountType;
use App\Enums\DealStatus;
use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Enums\ProductionStatus;
use App\Enums\SalarySheetStatus;
use App\Models\CashAccount;
use App\Models\Deal;
use App\Models\DealPayment;
use App\Models\Debt;
use App\Models\Expense;
use App\Models\MaterialStock;
use App\Models\ProductionLog;
use App\Models\SalarySheet;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Concerns\RemembersResults;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Финансовая сводка по данным, которые система уже ведёт: договоры и платежи
 * сделок, сдельная оплата цеха, оклады, закуп материалов.
 *
 * Расходы вне этих источников (аренда, налоги, реклама) появятся отдельным
 * контуром «Расходы» — см. README. Пока их здесь нет, «чистая
 * прибыль» — это поступления минус известные системе расходы.
 */
class FinanceSummary
{
    use RemembersResults;

    /** Период: null — за всё время, иначе месяц. */
    public function __construct(
        private readonly ?CarbonImmutable $from = null,
        private readonly ?CarbonImmutable $to = null,
    ) {}

    public static function allTime(): self
    {
        return new self;
    }

    public static function month(string $yearMonth): self
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $yearMonth.'-01')->startOfMonth();

        return new self($start, $start->endOfMonth());
    }

    /**
     * Произвольный период: «с 1 марта по вчера», квартал, полгода.
     *
     * Открытую границу подставляем сами, а не оставляем null: все суммы внутри
     * считаются через `whereBetween`, и одна пустая дата уронила бы запрос.
     * Открытое начало — от первой сделки в системе, открытый конец — сегодня.
     */
    public static function between(?string $from, ?string $to): self
    {
        if (blank($from) && blank($to)) {
            return self::allTime();
        }

        $start = blank($from)
            ? CarbonImmutable::parse(Deal::query()->min('created_at') ?? now())->startOfDay()
            : CarbonImmutable::parse($from)->startOfDay();

        $end = blank($to)
            ? CarbonImmutable::now()->endOfDay()
            : CarbonImmutable::parse($to)->endOfDay();

        // Перепутанные местами даты — не ошибка пользователя, а две даты в любом порядке.
        return $start->lessThanOrEqualTo($end) ? new self($start, $end) : new self($end, $start);
    }

    public function isAllTime(): bool
    {
        return $this->from === null;
    }

    /** Сумма договоров: все сделки продаж, кроме отменённых. За месяц — созданные в нём. */
    public function contracts(): float
    {
        return $this->once('contracts', function () {
            return round((float) $this->salesDeals()->sum('total_price'), 2);
        });
    }

    public function contractsCount(): int
    {
        return $this->once('contractsCount', function () {
            return $this->salesDeals()->count();
        });
    }

    /** Дебиторка: сколько клиенты ещё должны по открытым сделкам (без привязки к периоду). */
    public function receivables(): float
    {
        return $this->once('receivables', function () {
            return round((float) Deal::query()->sales()->open()
                ->selectRaw('SUM(CASE WHEN total_price > prepayment THEN total_price - prepayment ELSE 0 END) as due')
                ->value('due'), 2);
        });
    }

    public function receivablesCount(): int
    {
        return $this->once('receivablesCount', function () {
            return Deal::query()->sales()->open()->whereColumn('total_price', '>', 'prepayment')->count();
        });
    }

    /** Поступления: платежи по сделкам с чеком. */
    public function receipts(): float
    {
        return $this->once('receipts', function () {
            return round((float) $this->payments()->sum('amount'), 2);
        });
    }

    /** Наличные поступления — то, что лежит в кассе. */
    public function receiptsCash(): float
    {
        return $this->once('receiptsCash', function () {
            return round((float) $this->payments()->where('method', PaymentMethod::Cash->value)->sum('amount'), 2);
        });
    }

    /** Безналичные (карта, Kaspi, перевод) — банк. */
    public function receiptsBank(): float
    {
        return round($this->receipts() - $this->receiptsCash(), 2);
    }

    /** @return array<string, float> способ оплаты → сумма */
    public function receiptsByMethod(): array
    {
        return $this->once('receiptsByMethod', function () {
            return $this->payments()
                ->selectRaw('method, SUM(amount) as total')
                ->groupBy('method')
                ->pluck('total', 'method')
                ->map(fn ($v): float => round((float) $v, 2))
                ->all();
        });
    }

    /** Сдельная оплата цеха по закрытым этапам. */
    public function piecework(): float
    {
        return $this->once('piecework', function () {
            $query = ProductionLog::query()
                ->where('status', ProductionStatus::Done->value)
                ->whereNotNull('worker_id');

            if ($this->from) {
                $query->whereBetween('finished_at', [$this->from, $this->to]);
            }

            return round((float) $query->sum('payout'), 2);
        });
    }

    /**
     * Оклады. За месяц — сумма окладов сотрудников, принятых к концу месяца.
     * За всё время — расчётно: оклад × месяцы с даты приёма (без даты приёма — один месяц).
     */
    public function salaries(): float
    {
        return $this->once('salaries', function () {
            $users = User::query()->where('is_active', true)->where('salary', '>', 0)->get();

            if ($this->from) {
                $to = $this->to;

                return round((float) $users
                    ->filter(fn (User $u): bool => $u->hired_at === null || $u->hired_at->lte($to))
                    ->sum(fn (User $u): float => (float) $u->salary), 2);
            }

            return round((float) $users->sum(function (User $u): float {
                $months = $u->hired_at ? max(1, (int) $u->hired_at->diffInMonths(now()) + 1) : 1;

                return (float) $u->salary * $months;
            }), 2);
        });
    }

    /** Закуп материалов: приход на склад не по наряду (возвраты по нарядам — не закуп). */
    public function purchases(): float
    {
        return $this->once('purchases', function () {
            $query = StockMovement::query()
                ->where('type', StockMovement::TYPE_IN)
                ->whereNull('deal_id');

            if ($this->from) {
                $query->whereBetween('created_at', [$this->from, $this->to]);
            }

            return round((float) $query->selectRaw('SUM(quantity * price_per_unit) as total')->value('total'), 2);
        });
    }

    /**
     * Подтверждённые расходы по категориям (раздел «Расходы»).
     *
     * @return array<string, float> категория → сумма
     */
    public function approvedExpensesByCategory(): array
    {
        return $this->once('approvedExpensesByCategory', function () {
            $query = Expense::query()->approved();

            if ($this->from) {
                $query->whereBetween('spent_at', [$this->from->toDateString(), $this->to->toDateString()]);
            }

            return $query->selectRaw('category, SUM(amount) as total')
                ->groupBy('category')
                ->orderByDesc('total')
                ->pluck('total', 'category')
                ->map(fn ($v): float => round((float) $v, 2))
                ->all();
        });
    }

    /** Сумма утверждённых ведомостей за период (оклад + сдельно + бонусы − удержания). */
    public function payrollSheets(): float
    {
        return $this->once('payrollSheets', function () {
            return round((float) $this->sheetsQuery()->sum('total'), 2);
        });
    }

    public function hasPayrollSheets(): bool
    {
        return $this->once('hasPayrollSheets', function () {
            return $this->sheetsQuery()->exists();
        });
    }

    /** @return Builder<SalarySheet> */
    private function sheetsQuery(): Builder
    {
        $query = SalarySheet::query()->where('status', '!=', SalarySheetStatus::Draft->value);

        if ($this->from) {
            $query->where('month', $this->from->format('Y-m'));
        }

        return $query;
    }

    /** @return list<array{label: string, value: float}> */
    public function expenseLines(): array
    {
        return $this->once('expenseLines', function () {
            // Есть утверждённая ведомость — она и есть зарплата (оклад, сдельно, бонусы в ней).
            // Нет — оценка по окладам и сдельная оплата по закрытым этапам.
            $lines = $this->hasPayrollSheets()
                ? [['label' => 'Зарплата по ведомости', 'value' => $this->payrollSheets()]]
                : [
                    ['label' => 'Сдельная оплата цеха', 'value' => $this->piecework()],
                    ['label' => 'Оклады (оценка)', 'value' => $this->salaries()],
                ];

            $lines[] = ['label' => 'Закуп материалов', 'value' => $this->purchases()];

            foreach ($this->approvedExpensesByCategory() as $category => $total) {
                // Выплаты зарплаты — расходы категории «Зарплата»; в отчёте они уже учтены ведомостью.
                if ($category === ExpenseCategory::Salary->value) {
                    continue;
                }

                $lines[] = [
                    'label' => ExpenseCategory::tryFrom((string) $category)?->getLabel() ?? (string) $category,
                    'value' => $total,
                ];
            }

            return $lines;
        });
    }

    public function expenses(): float
    {
        return round(array_sum(array_column($this->expenseLines(), 'value')), 2);
    }

    /** Поступления минус известные расходы. */
    public function net(): float
    {
        return round($this->receipts() - $this->expenses(), 2);
    }

    /** Остаток по всем активным счетам типа «касса» на конец периода. */
    public function cashBalance(): float
    {
        return $this->once('cashBalance', function () {
            return $this->balanceOf(CashAccountType::Cash);
        });
    }

    /** Остаток по всем активным банковским счетам на конец периода. */
    public function bankBalance(): float
    {
        return $this->once('bankBalance', function () {
            return $this->balanceOf(CashAccountType::Bank);
        });
    }

    /** Долги компании: остаток по открытым (без привязки к периоду). */
    public function debts(): float
    {
        return $this->once('debts', function () {
            return round((float) Debt::query()->open()->selectRaw('SUM(amount - paid_amount) as due')->value('due'), 2);
        });
    }

    public function debtsCount(): int
    {
        return $this->once('debtsCount', function () {
            return Debt::query()->open()->count();
        });
    }

    /** @return Collection<int, Debt> */
    public function openDebts(): Collection
    {
        return $this->once('openDebts', function () {
            return Debt::query()->open()->orderBy('due_at')->get();
        });
    }

    /** @return Collection<int, CashAccount> */
    public function accounts(): Collection
    {
        return $this->once('accounts', function () {
            return CashAccount::query()->where('is_active', true)->orderBy('type')->orderBy('id')->get();
        });
    }

    public function hasAccounts(): bool
    {
        return $this->once('hasAccounts', function () {
            return CashAccount::query()->where('is_active', true)->exists();
        });
    }

    private function balanceOf(CashAccountType $type): float
    {
        return round((float) CashAccount::query()->where('is_active', true)->where('type', $type->value)->get()
            ->sum(fn (CashAccount $account): float => $account->balance($this->to)), 2);
    }

    /** Стоимость остатков склада по учётной цене. */
    public function stockValue(): float
    {
        return $this->once('stockValue', function () {
            return round((float) MaterialStock::query()->where('is_active', true)
                ->selectRaw('SUM(quantity * price_per_unit) as total')->value('total'), 2);
        });
    }

    /** @return Builder<Deal> */
    private function salesDeals(): Builder
    {
        $query = Deal::query()->sales()->where('status_id', '!=', DealStatus::Cancelled->value);

        if ($this->from) {
            $query->whereBetween('created_at', [$this->from, $this->to]);
        }

        return $query;
    }

    /** @return Builder<DealPayment> */
    private function payments(): Builder
    {
        $query = DealPayment::query()->whereHas('deal', fn ($q) => $q->sales());

        if ($this->from) {
            $query->whereBetween('paid_at', [$this->from->toDateString(), $this->to->toDateString()]);
        }

        return $query;
    }
}
