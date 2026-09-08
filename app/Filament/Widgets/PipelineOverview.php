<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\DealStatus;
use App\Models\Deal;
use App\Models\ProductionLog;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PipelineOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Сводка по воронкам';

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return auth()->user()?->role->seesMoney() ?? false;
    }

    protected function getStats(): array
    {
        $salesOpen = Deal::query()->sales()->open()->count();
        $portfolio = (float) Deal::query()->sales()->open()->sum('total_price');
        $inProduction = Deal::query()->factory()->open()->count();
        $readyToShip = Deal::query()->sales()->where('status_id', DealStatus::ReadyToShip->value)->count();

        $payout = (float) ProductionLog::query()
            ->whereBetween('finished_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('payout');

        $symbol = config('gravit.currency.symbol');
        $money = fn (float $value): string => number_format($value, 0, ',', ' ').' '.$symbol;

        return [
            Stat::make('Открытых сделок', (string) $salesOpen)
                ->description($money($portfolio).' в работе')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('info'),

            Stat::make('Нарядов в цеху', (string) $inProduction)
                ->description('производственная загрузка')
                ->descriptionIcon('heroicon-m-cog-6-tooth')
                ->color($inProduction > 0 ? 'warning' : 'gray'),

            Stat::make('Готово к отгрузке', (string) $readyToShip)
                ->description('ждут логистику')
                ->descriptionIcon('heroicon-m-cube')
                ->color($readyToShip > 0 ? 'success' : 'gray'),

            Stat::make('Сдельно за месяц', $money($payout))
                ->description('начислено цеху по закрытым этапам')
                ->descriptionIcon('heroicon-m-wrench-screwdriver')
                ->color('primary'),
        ];
    }
}
