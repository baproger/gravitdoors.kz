<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Services\AccessControl;
use App\Services\FinanceSummary;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Финансы — обзор: договоры, дебиторка, поступления, расходы, прибыль.
 * Первый экран финансового контура; остальные разделы — в README.
 */
class FinanceOverview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static string|UnitEnum|null $navigationGroup = 'Финансы';

    protected static ?string $navigationLabel = 'Обзор';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'finance';

    protected string $view = 'filament.pages.finance-overview';

    /** Месяц Y-m; пусто — за всё время. */
    #[Url(except: '')]
    public string $month = '';

    /** Произвольный период. Заполнен — перекрывает выбор месяца. */
    public ?string $from = null;

    public ?string $to = null;

    public static function canAccess(): bool
    {
        return AccessControl::can(Permission::FinanceOverview);
    }

    public function getTitle(): string
    {
        return 'Финансы — обзор';
    }

    public function getSubheading(): ?string
    {
        return 'Картина целиком по данным сделок, платежей, цеха и склада. Записи ведутся в своих разделах.';
    }

    public function summary(): FinanceSummary
    {
        if (filled($this->from) || filled($this->to)) {
            return FinanceSummary::between($this->from, $this->to);
        }

        return $this->month !== '' ? FinanceSummary::month($this->month) : FinanceSummary::allTime();
    }

    /** Выбор даты отменяет выбор месяца: два периода сразу — это неоднозначно. */
    public function updatedFrom(): void
    {
        $this->month = '';
    }

    public function updatedTo(): void
    {
        $this->month = '';
    }

    /** И наоборот: выбрали месяц — произвольный период сбрасывается. */
    public function updatedMonth(): void
    {
        $this->from = null;
        $this->to = null;
    }

    public function resetPeriod(): void
    {
        $this->month = now()->format('Y-m');
        $this->from = null;
        $this->to = null;
    }

    /** @return array<string, string> */
    public function monthOptions(): array
    {
        $options = ['' => 'За всё время'];

        foreach (range(0, 11) as $back) {
            $date = now()->subMonths($back);
            $options[$date->format('Y-m')] = mb_convert_case($date->translatedFormat('F Y'), MB_CASE_TITLE);
        }

        return $options;
    }

    public function periodLabel(): string
    {
        if (filled($this->from) || filled($this->to)) {
            $format = static fn (?string $date): ?string => blank($date) ? null : Carbon::parse($date)->format('d.m.Y');

            return match (true) {
                filled($this->from) && filled($this->to) => 'с '.$format($this->from).' по '.$format($this->to),
                filled($this->from) => 'с '.$format($this->from),
                default => 'по '.$format($this->to),
            };
        }

        return $this->month === '' ? 'за всё время' : 'за '.($this->monthOptions()[$this->month] ?? $this->month);
    }
}
