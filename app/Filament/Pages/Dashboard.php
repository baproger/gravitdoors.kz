<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Actions\NewDealAction;
use App\Services\DashboardStats;
use App\Support\Period;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * Инфопанель: всё, что нужно увидеть с порога, на одном экране.
 *
 * Свой экран, а не набор виджетов Filament: показателей много, они связаны
 * общим периодом, и раскладка плитками читается быстрее, чем десяток
 * независимых карточек. Что именно видно — решает реестр прав, поэтому у
 * менеджера это его воронка, у цеха — загрузка, у директора — всё сразу.
 */
class Dashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Инфопанель';

    protected static ?int $navigationSort = -2;

    protected static ?string $slug = '/';

    protected string $view = 'filament.pages.dashboard';

    /** Период живёт в адресной строке: ссылкой на отчёт можно поделиться. */
    #[Url(except: Period::MONTH)]
    public string $period = Period::MONTH;

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    protected function getHeaderActions(): array
    {
        return [NewDealAction::make()];
    }

    public function getTitle(): string
    {
        return 'Инфопанель';
    }

    public function getSubheading(): ?string
    {
        return 'Показатели '.$this->periodValue()->hint().'. Период меняется переключателем справа.';
    }

    public function periodValue(): Period
    {
        return Period::make($this->period, $this->from ?: null, $this->to ?: null);
    }

    public function stats(): DashboardStats
    {
        return new DashboardStats($this->periodValue(), auth()->user());
    }

    /** @return array<string, string> */
    public function periodOptions(): array
    {
        return Period::options();
    }

    public function setPeriod(string $key): void
    {
        $this->period = array_key_exists($key, Period::options()) ? $key : Period::MONTH;

        if ($this->period !== Period::CUSTOM) {
            $this->from = '';
            $this->to = '';
        }
    }
}
