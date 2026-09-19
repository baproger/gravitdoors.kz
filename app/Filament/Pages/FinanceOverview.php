<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Services\AccessControl;
use App\Services\FinanceSummary;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Финансы — обзор: договоры, дебиторка, поступления, расходы, прибыль.
 * Первый экран финансового контура; остальные разделы — по finance-plan.md.
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
        return $this->month !== '' ? FinanceSummary::month($this->month) : FinanceSummary::allTime();
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
        return $this->month === '' ? 'за всё время' : 'за '.($this->monthOptions()[$this->month] ?? $this->month);
    }
}
