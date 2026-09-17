<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\ProductionStatus;
use App\Enums\UserRole;
use App\Models\ProductionLog;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Сдельная зарплата цеха за месяц.
 *
 * Считается по production_logs, где расценка зафиксирована в момент закрытия
 * этапа: пересмотр тарифов не меняет уже начисленное за прошлые месяцы.
 */
class Payroll extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Финансы';

    protected static ?string $navigationLabel = 'Зарплата цеха';

    protected static ?int $navigationSort = 70;

    protected static ?string $slug = 'payroll';

    protected string $view = 'filament.pages.payroll';

    /** Месяц в формате Y-m. */
    public string $month = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->role->seesMoney() ?? false;
    }

    public function mount(): void
    {
        $this->month = now()->format('Y-m');
    }

    public function getTitle(): string
    {
        return 'Зарплата цеха';
    }

    public function getSubheading(): ?string
    {
        return 'Начислено по закрытым этапам производства. Расценка зафиксирована на момент закрытия этапа.';
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
     * Строки отчёта: сотрудник, закрытые этапы, часы, начислено.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(): Collection
    {
        [$from, $to] = $this->period();

        $logs = ProductionLog::query()
            ->with(['stage', 'worker'])
            ->whereNotNull('worker_id')
            ->where('status', ProductionStatus::Done->value)
            ->whereBetween('finished_at', [$from, $to])
            ->get()
            ->groupBy('worker_id');

        return $logs
            ->map(function (Collection $workerLogs): array {
                /** @var ProductionLog $first */
                $first = $workerLogs->first();

                $hours = $workerLogs->sum(fn (ProductionLog $log): float => $log->durationHours() ?? 0.0);
                $planned = $workerLogs->sum(fn (ProductionLog $log): float => (float) $log->stage->estimated_hours);

                return [
                    'worker' => $first->worker,
                    'stages' => $workerLogs->count(),
                    'hours' => round($hours, 1),
                    'planned' => round($planned, 1),
                    'payout' => round($workerLogs->sum(fn (ProductionLog $log): float => (float) $log->payout), 2),
                ];
            })
            ->sortByDesc('payout')
            ->values();
    }

    public function total(): float
    {
        return round($this->rows()->sum('payout'), 2);
    }

    /** Сотрудники цеха без выработки за месяц — их не должно потеряться в отчёте. */
    public function idleWorkers(): Collection
    {
        $paid = $this->rows()->pluck('worker.id');

        return User::query()
            ->whereIn('role', [UserRole::Worker->value, UserRole::Master->value])
            ->where('is_active', true)
            ->whereNotIn('id', $paid)
            ->orderBy('name')
            ->get();
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function period(): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $this->month.'-01')->startOfMonth();

        return [$start, $start->endOfMonth()];
    }
}
