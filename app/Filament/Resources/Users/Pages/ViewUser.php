<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Enums\DealStatus;
use App\Enums\ProductionStatus;
use App\Filament\Resources\Users\UserResource;
use App\Models\Bonus;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\ProductionLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;

/**
 * Карточка сотрудника: что человек сделал и сколько за это получит.
 *
 * Считается по тем же данным, что и остальная система: сделки — по
 * ответственному, выработка цеха — по закрытым этапам production_logs,
 * активность — по ленте событий.
 */
class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected string $view = 'filament.resources.users.view';

    /** Месяц отчёта в формате Y-m. */
    public string $month = '';

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->month = now()->format('Y-m');
    }

    public function getTitle(): string
    {
        return $this->employee()->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Редактировать'),
        ];
    }

    public function employee(): User
    {
        return $this->record;
    }

    /** @return array<string, string> */
    public function monthOptions(): array
    {
        return collect(range(0, 11))
            ->mapWithKeys(function (int $back): array {
                $date = now()->subMonths($back);

                return [$date->format('Y-m') => mb_convert_case($date->translatedFormat('F Y'), MB_CASE_TITLE)];
            })
            ->all();
    }

    /**
     * Кольца показателей.
     *
     * @return list<array{label: string, hint: string, value: int, total: int, color: string}>
     */
    public function metrics(): array
    {
        $deals = Deal::query()->sales()->where('manager_id', $this->employee()->id);
        $dealsTotal = (clone $deals)->count();
        $dealsWon = (clone $deals)->whereIn('status_id', [
            DealStatus::ReadyToShip->value,
            DealStatus::Shipped->value,
            DealStatus::Installed->value,
            DealStatus::Completed->value,
        ])->count();

        // Наряды цеха считаются по работам сотрудника, а не по manager_id: на наряд
        // копируется менеджер продаж, и у мастера тут всегда был ноль.
        $workedOrderIds = ProductionLog::query()
            ->where('worker_id', $this->employee()->id)
            ->where('status', ProductionStatus::Done->value)
            ->distinct()
            ->pluck('deal_id');
        $ordersTotal = $workedOrderIds->count();
        $ordersDone = Deal::query()->factoryOrders()->whereIn('id', $workedOrderIds)
            ->where('status_id', DealStatus::Completed->value)->count();

        [$from, $to] = $this->period();
        $stagesDone = ProductionLog::query()
            ->where('worker_id', $this->employee()->id)
            ->where('status', ProductionStatus::Done->value)
            ->whereBetween('finished_at', [$from, $to])
            ->count();
        $stagesAll = ProductionLog::query()->where('worker_id', $this->employee()->id)->count();

        return [
            ['label' => 'Сделки', 'hint' => 'доведены до отгрузки', 'value' => $dealsWon, 'total' => max($dealsTotal, 1), 'color' => 'primary'],
            ['label' => 'Наряды цеха', 'hint' => 'закрыто', 'value' => $ordersDone, 'total' => max($ordersTotal, 1), 'color' => 'info'],
            ['label' => 'Этапы цеха', 'hint' => 'за месяц', 'value' => $stagesDone, 'total' => max($stagesAll, 1), 'color' => 'warning'],
        ];
    }

    /**
     * Активность за последние 7 дней: действия в системе и закрытые этапы цеха.
     *
     * @return list<array{day: string, events: int, stages: int}>
     */
    public function weeklyActivity(): array
    {
        $from = CarbonImmutable::now()->subDays(6)->startOfDay();

        $events = DealEvent::query()
            ->where('user_id', $this->employee()->id)
            ->where('created_at', '>=', $from)
            ->get()
            ->groupBy(fn (DealEvent $event): string => $event->created_at->toDateString());

        $stages = ProductionLog::query()
            ->where('worker_id', $this->employee()->id)
            ->where('status', ProductionStatus::Done->value)
            ->where('finished_at', '>=', $from)
            ->get()
            ->groupBy(fn (ProductionLog $log): string => $log->finished_at->toDateString());

        return collect(range(0, 6))
            ->map(function (int $offset) use ($from, $events, $stages): array {
                $date = $from->addDays($offset);
                $key = $date->toDateString();

                return [
                    'day' => mb_convert_case($date->translatedFormat('D'), MB_CASE_TITLE),
                    'today' => $date->isToday(),
                    'events' => $events->get($key)?->count() ?? 0,
                    'stages' => $stages->get($key)?->count() ?? 0,
                ];
            })
            ->all();
    }

    /** @return array{salary: float, piecework: float, total: float, stages: int} */
    public function payroll(): array
    {
        [$from, $to] = $this->period();

        $piecework = (float) ProductionLog::query()
            ->where('worker_id', $this->employee()->id)
            ->whereBetween('finished_at', [$from, $to])
            ->sum('payout');

        $salary = (float) $this->employee()->salary;
        $bonuses = (float) Bonus::query()->approved()->forMonth($this->month)->where('user_id', $this->employee()->id)->sum('amount');

        return [
            'salary' => $salary,
            'piecework' => $piecework,
            'bonuses' => $bonuses,
            'total' => round($salary + $piecework + $bonuses, 2),
            'stages' => ProductionLog::query()
                ->where('worker_id', $this->employee()->id)
                ->where('status', ProductionStatus::Done->value)
                ->whereBetween('finished_at', [$from, $to])
                ->count(),
        ];
    }

    /** @return Collection<int, Deal> */
    public function deals(): Collection
    {
        return Deal::query()
            ->sales()
            ->where('manager_id', $this->employee()->id)
            ->with('currentStage')
            ->orderByDesc('id')
            ->limit(8)
            ->get();
    }

    /** @return Collection<int, ProductionLog> */
    public function workshopLogs(): Collection
    {
        return ProductionLog::query()
            ->where('worker_id', $this->employee()->id)
            ->with(['stage', 'deal.parentDeal'])
            ->orderByDesc('id')
            ->limit(8)
            ->get();
    }

    /** @return Collection<int, DealEvent> */
    public function recentEvents(): Collection
    {
        return DealEvent::query()
            ->where('user_id', $this->employee()->id)
            ->with('deal')
            ->orderByDesc('id')
            ->limit(8)
            ->get();
    }

    /**
     * Свою зарплату видит каждый, чужую — только администратор.
     * Менеджеру список коллег нужен для назначения ответственных,
     * а не для сверки чужих окладов.
     */
    public function canSeeMoney(): bool
    {
        return auth()->id() === $this->employee()->id
            || (auth()->user()?->isAdmin() ?? false);
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function period(): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $this->month.'-01')->startOfMonth();

        return [$start, $start->endOfMonth()];
    }
}
