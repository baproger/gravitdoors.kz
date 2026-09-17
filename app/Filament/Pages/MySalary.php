<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\ProductionStatus;
use App\Models\Bonus;
use App\Models\ProductionLog;
use App\Models\SalarySheet;
use App\Services\PayrollService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Моя зарплата: каждый сотрудник видит только своё — ведомость, бонусы, выплаты.
 * Чужие цифры отсюда не достать: все запросы привязаны к auth()->id().
 */
class MySalary extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static string|UnitEnum|null $navigationGroup = 'Финансы';

    protected static ?string $navigationLabel = 'Моя зарплата';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'my-salary';

    protected string $view = 'filament.pages.my-salary';

    #[Url]
    public string $month = '';

    public function mount(): void
    {
        if ($this->month === '') {
            $this->month = now()->format('Y-m');
        }
    }

    public function getTitle(): string
    {
        return 'Моя зарплата';
    }

    public function getSubheading(): ?string
    {
        return 'Оклад, сдельная выработка, бонусы и выплаты — только ваши.';
    }

    /** @return array<string, string> */
    public function monthOptions(): array
    {
        $options = [];

        foreach (range(0, 11) as $back) {
            $date = now()->subMonths($back);
            $options[$date->format('Y-m')] = mb_convert_case($date->translatedFormat('F Y'), MB_CASE_TITLE);
        }

        return $options;
    }

    public function sheet(): ?SalarySheet
    {
        return SalarySheet::query()->where('user_id', auth()->id())->where('month', $this->month)->with('payments')->first();
    }

    /** Пока ведомости нет — предварительный расчёт из тех же источников. */
    public function preview(): array
    {
        [$from, $to] = PayrollService::period($this->month);
        $user = auth()->user();

        $salary = app(PayrollService::class)->proratedSalary($user, $from, $to);
        $piecework = round((float) ProductionLog::query()->where('worker_id', $user->id)
            ->where('status', ProductionStatus::Done->value)->whereBetween('finished_at', [$from, $to])->sum('payout'), 2);
        $bonuses = round((float) Bonus::query()->approved()->forMonth($this->month)->where('user_id', $user->id)->sum('amount'), 2);

        return ['salary' => $salary, 'piecework' => $piecework, 'bonuses' => $bonuses, 'total' => round($salary + $piecework + $bonuses, 2)];
    }

    /** @return Collection<int, Bonus> */
    public function bonuses(): Collection
    {
        return Bonus::query()->where('user_id', auth()->id())->forMonth($this->month)->with('deal')->orderByDesc('id')->get();
    }

    /** @return Collection<int, ProductionLog> */
    public function stages(): Collection
    {
        [$from, $to] = PayrollService::period($this->month);

        return ProductionLog::query()->where('worker_id', auth()->id())
            ->where('status', ProductionStatus::Done->value)->whereBetween('finished_at', [$from, $to])
            ->with(['stage', 'deal'])->orderByDesc('finished_at')->get();
    }
}
