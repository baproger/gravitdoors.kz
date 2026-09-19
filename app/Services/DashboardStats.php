<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DealSource;
use App\Enums\DealStatus;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\PipelineType;
use App\Enums\ProductionStatus;
use App\Models\Bonus;
use App\Models\CashAccount;
use App\Models\Deal;
use App\Models\DealPayment;
use App\Models\Debt;
use App\Models\Expense;
use App\Models\FactoryStage;
use App\Models\MaterialStock;
use App\Models\ProductionLog;
use App\Models\SalarySheet;
use App\Models\User;
use App\Support\Concerns\RemembersResults;
use App\Support\Period;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Числа инфопанели за выбранный период.
 *
 * Каждый показатель считается по тем записям, которые пользователю видны
 * (`Deal::scopeVisibleTo`), поэтому менеджер видит свою воронку, а директор —
 * всю. Что показывать, а что скрыть, решает реестр прав: суммы — `kanban.money`,
 * сводные показатели — `kanban.totals`, цех — `work.factory_kanban`.
 */
class DashboardStats
{
    use RemembersResults;

    public function __construct(
        private readonly Period $period,
        private readonly ?User $user = null,
    ) {}

    private function viewer(): ?User
    {
        return $this->user ?? auth()->user();
    }

    // ---------- что показывать -------------------------------------------

    public function seesSales(): bool
    {
        return AccessControl::allows($this->viewer(), Permission::WorkSalesKanban);
    }

    public function seesFactory(): bool
    {
        return AccessControl::allows($this->viewer(), Permission::WorkFactoryKanban);
    }

    public function seesMoney(): bool
    {
        return AccessControl::allows($this->viewer(), Permission::KanbanMoney);
    }

    public function seesFinance(): bool
    {
        return AccessControl::allows($this->viewer(), Permission::FinanceOverview);
    }

    public function seesWarehouse(): bool
    {
        return AccessControl::allows($this->viewer(), Permission::WorkMaterials);
    }

    public function seesPayroll(): bool
    {
        return AccessControl::allows($this->viewer(), Permission::FinanceSalarySheets);
    }

    public function seesTotals(): bool
    {
        return AccessControl::allows($this->viewer(), Permission::KanbanTotals);
    }

    // ---------- продажи ---------------------------------------------------

    /** @return Builder<Deal> */
    private function deals(): Builder
    {
        return Deal::query()->visibleTo($this->viewer())->sales();
    }

    public function newDeals(): int
    {
        return $this->once('newDeals', function () {
            return $this->period->apply($this->deals(), 'created_at')->count();
        });
    }

    public function newDealsSum(): float
    {
        return $this->once('newDealsSum', function () {
            return round((float) $this->period->apply($this->deals(), 'created_at')->sum('total_price'), 2);
        });
    }

    public function wonDeals(): int
    {
        return $this->once('wonDeals', function () {
            return $this->period->apply(
                $this->deals()->where('status_id', DealStatus::Completed->value), 'updated_at'
            )->count();
        });
    }

    public function lostDeals(): int
    {
        return $this->once('lostDeals', function () {
            return $this->period->apply(
                $this->deals()->where('status_id', DealStatus::Cancelled->value), 'updated_at'
            )->count();
        });
    }

    /** Доля выигранных среди закрытых за период. */
    public function conversion(): ?float
    {
        $closed = $this->wonDeals() + $this->lostDeals();

        return $closed > 0 ? round($this->wonDeals() / $closed * 100, 1) : null;
    }

    public function averageCheck(): float
    {
        $count = $this->newDeals();

        return $count > 0 ? round($this->newDealsSum() / $count, 2) : 0.0;
    }

    public function openDeals(): int
    {
        return $this->once('openDeals', function () {
            return $this->deals()->open()->count();
        });
    }

    public function openDealsSum(): float
    {
        return $this->once('openDealsSum', function () {
            return round((float) $this->deals()->open()->sum('total_price'), 2);
        });
    }

    public function overdueDeals(): int
    {
        return $this->once('overdueDeals', function () {
            return $this->deals()->open()->whereDate('due_date', '<', today())->count();
        });
    }

    public function blockedShipments(): int
    {
        return $this->once('blockedShipments', function () {
            return $this->deals()->open()->whereNotNull('shipment_blocked_at')->count();
        });
    }

    public function receivables(): float
    {
        return $this->once('receivables', function () {
            return round((float) $this->deals()->open()
                ->selectRaw('SUM(CASE WHEN total_price > prepayment THEN total_price - prepayment ELSE 0 END) as due')
                ->value('due'), 2);
        });
    }

    /**
     * Воронка: сколько сделок и на какую сумму стоит на каждом этапе.
     *
     * @return Collection<int, array{name: string, color: string, count: int, sum: float}>
     */
    public function funnel(): Collection
    {
        return $this->once('funnel', function () {
            // toBase(): у агрегата нет модели, это просто строки с посчитанными колонками.
            $counts = $this->deals()->open()
                ->toBase()
                ->selectRaw('current_stage_id, COUNT(*) as c, SUM(total_price) as s')
                ->groupBy('current_stage_id')
                ->get()
                ->keyBy('current_stage_id');

            return FactoryStage::query()->ofPipeline(PipelineType::Sales)->active()->ordered()->get()
                ->map(fn (FactoryStage $stage): array => [
                    'name' => $stage->name,
                    'color' => $stage->color,
                    'count' => (int) ($counts->get($stage->id)->c ?? 0),
                    'sum' => round((float) ($counts->get($stage->id)->s ?? 0), 2),
                ]);
        });
    }

    /** @return Collection<int, array{label: string, count: int, sum: float}> */
    public function sources(): Collection
    {
        return $this->once('sources', function () {
            return $this->period->apply($this->deals(), 'created_at')
                ->toBase()
                ->selectRaw('source, COUNT(*) as c, SUM(total_price) as s')
                ->groupBy('source')
                ->orderByDesc('c')
                ->get()
                ->map(fn ($row): array => [
                    'label' => DealSource::tryFrom((string) $row->source)?->getLabel() ?? 'Не указан',
                    'count' => (int) $row->c,
                    'sum' => round((float) $row->s, 2),
                ])
                ->values();
        });
    }

    /** @return Collection<int, array{name: string, count: int<0, max>, sum: float}> */
    public function byManager(): Collection
    {
        return $this->once('byManager', function () {
            return $this->period->apply($this->deals(), 'created_at')
                ->with('manager')
                ->get()
                ->groupBy('manager_id')
                ->map(fn (Collection $deals): array => [
                    'name' => (string) ($deals->first()->manager->name ?? 'Без ответственного'),
                    'count' => (int) $deals->count(),
                    'sum' => round((float) $deals->sum('total_price'), 2),
                ])
                ->sortByDesc('sum')
                ->values();
        });
    }

    // ---------- деньги ----------------------------------------------------

    /** @return Builder<DealPayment> */
    private function payments(): Builder
    {
        return DealPayment::query()->whereHas('deal', fn (Builder $q) => $q->visibleTo($this->viewer())->sales());
    }

    public function income(): float
    {
        return $this->once('income', function () {
            return round((float) $this->period->apply($this->payments(), 'paid_at')->sum('amount'), 2);
        });
    }

    public function incomeCash(): float
    {
        return $this->once('incomeCash', function () {
            return round((float) $this->period->apply($this->payments(), 'paid_at')
                ->where('method', PaymentMethod::Cash->value)->sum('amount'), 2);
        });
    }

    public function expenses(): float
    {
        return $this->once('expenses', function () {
            return round((float) $this->period->apply(
                Expense::query()->where('status', ExpenseStatus::Approved->value), 'spent_at'
            )->sum('amount'), 2);
        });
    }

    /** @return Collection<int, array{label: string, value: float}> */
    public function expensesByCategory(): Collection
    {
        return $this->once('expensesByCategory', function () {
            return $this->period->apply(
                Expense::query()->where('status', ExpenseStatus::Approved->value), 'spent_at'
            )
                ->toBase()
                ->selectRaw('category, SUM(amount) as total')
                ->groupBy('category')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($row): array => [
                    'label' => ExpenseCategory::tryFrom((string) $row->category)?->getLabel() ?? (string) $row->category,
                    'value' => round((float) $row->total, 2),
                ])
                ->values();
        });
    }

    public function profit(): float
    {
        return round($this->income() - $this->expenses(), 2);
    }

    public function debts(): float
    {
        return $this->once('debts', function () {
            return round((float) Debt::query()->open()->selectRaw('SUM(amount - paid_amount) as d')->value('d'), 2);
        });
    }

    public function cashBalance(): float
    {
        return $this->once('cashBalance', function () {
            return $this->balanceOf('cash');
        });
    }

    public function bankBalance(): float
    {
        return $this->once('bankBalance', function () {
            return $this->balanceOf('bank');
        });
    }

    private function balanceOf(string $type): float
    {
        return round((float) CashAccount::query()->where('is_active', true)->where('type', $type)->get()
            ->sum(fn (CashAccount $account): float => $account->balance($this->period->to)), 2);
    }

    /**
     * Поступления и расходы по месяцам за последний год — для графика.
     *
     * @return Collection<int, array{label: string, income: float, expense: float}>
     */
    public function monthlyFlow(int $months = 12): Collection
    {
        return $this->once("monthlyFlow.{$months}", function () use ($months): Collection {
            $from = CarbonImmutable::now()->subMonths($months - 1)->startOfMonth();

            // Два запроса вместо 24 (по одному на месяц), а месяц из даты берём
            // в PHP: у SQLite это strftime, у MySQL — DATE_FORMAT, и держать две
            // версии SQL ради группировки по месяцу незачем.
            $income = $this->payments()->toBase()
                ->where('paid_at', '>=', $from->toDateString())
                ->get(['paid_at', 'amount'])
                ->groupBy(fn ($row): string => substr((string) $row->paid_at, 0, 7))
                ->map(fn ($rows): float => (float) $rows->sum('amount'));

            $expense = Expense::query()->toBase()
                ->where('status', ExpenseStatus::Approved->value)
                ->where('spent_at', '>=', $from->toDateString())
                ->get(['spent_at', 'amount'])
                ->groupBy(fn ($row): string => substr((string) $row->spent_at, 0, 7))
                ->map(fn ($rows): float => (float) $rows->sum('amount'));

            $rows = collect();

            for ($i = 0; $i < $months; $i++) {
                $month = $from->addMonths($i);
                $key = $month->format('Y-m');

                $rows->push([
                    'label' => mb_substr($month->translatedFormat('F'), 0, 3),
                    'income' => round((float) ($income[$key] ?? 0), 2),
                    'expense' => round((float) ($expense[$key] ?? 0), 2),
                ]);
            }

            return $rows;
        });
    }

    // ---------- производство ---------------------------------------------

    /** @return Builder<Deal> */
    private function orders(): Builder
    {
        return Deal::query()->visibleTo($this->viewer())->factoryOrders();
    }

    public function ordersInWork(): int
    {
        return $this->once('ordersInWork', function () {
            return $this->orders()->open()->count();
        });
    }

    public function ordersFinished(): int
    {
        return $this->once('ordersFinished', function () {
            return $this->period->apply(
                $this->orders()->where('status_id', DealStatus::Completed->value), 'production_finished_at'
            )->count();
        });
    }

    public function stagesClosed(): int
    {
        return $this->once('stagesClosed', function () {
            return $this->period->apply(
                ProductionLog::query()->where('status', ProductionStatus::Done->value), 'finished_at'
            )->count();
        });
    }

    public function piecework(): float
    {
        return $this->once('piecework', function () {
            return round((float) $this->period->apply(
                ProductionLog::query()->where('status', ProductionStatus::Done->value), 'finished_at'
            )->sum('payout'), 2);
        });
    }

    /** Средний срок изготовления в днях по закрытым за период нарядам. */
    public function averageCycleDays(): ?float
    {
        return $this->once('averageCycleDays', function () {
            $orders = $this->period->apply(
                $this->orders()->where('status_id', DealStatus::Completed->value)
                    ->whereNotNull('production_started_at')->whereNotNull('production_finished_at'),
                'production_finished_at'
            )->get(['production_started_at', 'production_finished_at']);

            if ($orders->isEmpty()) {
                return null;
            }

            $days = $orders->sum(fn (Deal $order): float => $order->production_started_at->diffInDays($order->production_finished_at, true));

            return round($days / $orders->count(), 1);
        });
    }

    /** Наряды, стоящие на этапе дольше норматива. */
    public function stuckOrders(): int
    {
        return $this->once('stuckOrders', function () {
            return $this->orders()->open()->with('currentStage')->get()
                ->filter(fn (Deal $order): bool => $order->isStageOverdue())
                ->count();
        });
    }

    /**
     * Загрузка цеха: сколько нарядов на каждом этапе прямо сейчас.
     *
     * @return Collection<int, array{name: string, color: string, count: int}>
     */
    public function workshopLoad(): Collection
    {
        return $this->once('workshopLoad', function () {
            $counts = $this->orders()->open()
                ->selectRaw('current_stage_id, COUNT(*) as c')
                ->groupBy('current_stage_id')
                ->pluck('c', 'current_stage_id');

            return FactoryStage::query()->ofPipeline(PipelineType::Factory)->active()->ordered()->get()
                ->map(fn (FactoryStage $stage): array => [
                    'name' => $stage->name,
                    'color' => $stage->color,
                    'count' => (int) ($counts[$stage->id] ?? 0),
                ]);
        });
    }

    /** @return Collection<int, array{name: string, stages: int<0, max>, payout: float}> */
    public function topWorkers(int $limit = 5): Collection
    {
        return $this->period->apply(
            ProductionLog::query()->where('status', ProductionStatus::Done->value)->whereNotNull('worker_id'),
            'finished_at'
        )
            ->with('worker')
            ->get()
            ->groupBy('worker_id')
            ->map(fn (Collection $logs): array => [
                'name' => (string) ($logs->first()->worker->name ?? '—'),
                'stages' => (int) $logs->count(),
                'payout' => round((float) $logs->sum('payout'), 2),
            ])
            ->sortByDesc('payout')
            ->take($limit)
            ->values();
    }

    // ---------- склад и люди ---------------------------------------------

    public function lowStockCount(): int
    {
        return $this->once('lowStockCount', function () {
            return MaterialStock::query()->where('is_active', true)->belowLimit()->count();
        });
    }

    /** @return Collection<int, MaterialStock> */
    public function lowStock(int $limit = 6): Collection
    {
        return MaterialStock::query()->where('is_active', true)->belowLimit()->orderBy('name')->limit($limit)->get();
    }

    public function stockValue(): float
    {
        return $this->once('stockValue', function () {
            return round((float) MaterialStock::query()->where('is_active', true)
                ->selectRaw('SUM(quantity * price_per_unit) as v')->value('v'), 2);
        });
    }

    public function payrollDue(): float
    {
        return $this->once('payrollDue', function () {
            $month = $this->period->monthKey();

            return round((float) SalarySheet::query()
                ->when($month, fn (Builder $q) => $q->where('month', $month))
                ->where('status', '!=', 'draft')
                ->get()
                ->sum(fn (SalarySheet $sheet): float => $sheet->remaining()), 2);
        });
    }

    public function bonusesPending(): float
    {
        return $this->once('bonusesPending', function () {
            return round((float) Bonus::query()->where('status', 'pending')->sum('amount'), 2);
        });
    }

    public function activeStaff(): int
    {
        return $this->once('activeStaff', function () {
            return User::query()->where('is_active', true)->count();
        });
    }

    /** Сравнение с предыдущим отрезком: рост поступлений в процентах. */
    public function incomeTrend(): ?float
    {
        return $this->once('incomeTrend', function () {
            $previous = $this->period->previous();

            if (! $previous) {
                return null;
            }

            $before = round((float) $previous->apply($this->payments(), 'paid_at')->sum('amount'), 2);

            if ($before <= 0.0) {
                return null;
            }

            return round(($this->income() - $before) / $before * 100, 1);
        });
    }
}
