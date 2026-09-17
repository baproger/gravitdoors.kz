<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Filament\Pages\OverdueDeals;
use App\Filament\Resources\MaterialStocks\MaterialStockResource;
use App\Models\Deal;
use App\Models\MaterialStock;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Ежедневная проверка: то, что иначе замечают, когда уже поздно —
 * кончившийся материал и наряд, застрявший на этапе.
 */
class DailyCheck extends Command
{
    protected $signature = 'gravit:daily-check {--dry : Только показать, без отправки уведомлений}';

    protected $description = 'Проверяет остатки склада и просроченные этапы цеха, уведомляет руководство';

    public function handle(): int
    {
        $lowStock = MaterialStock::query()->where('is_active', true)->belowLimit()->orderBy('name')->get();
        $overdue = $this->overdueStages();

        $this->table(
            ['Проверка', 'Найдено'],
            [
                ['Материалы ниже минимума', $lowStock->count()],
                ['Этапы дольше норматива', $overdue->count()],
            ],
        );

        if ($this->option('dry')) {
            $this->info('Пробный запуск: уведомления не отправлены.');

            return self::SUCCESS;
        }

        if ($lowStock->isNotEmpty()) {
            $this->notifyLowStock($lowStock);
        }

        if ($overdue->isNotEmpty()) {
            $this->notifyOverdue($overdue);
        }

        if ($lowStock->isEmpty() && $overdue->isEmpty()) {
            $this->info('Всё в порядке, уведомлять не о чем.');
        }

        return self::SUCCESS;
    }

    /**
     * Открытые наряды, стоящие на этапе дольше норматива.
     *
     * Считается так же, как на канбане и странице «Просроченные»: по сумме всех
     * заходов на этап (Deal::isStageOverdue), а не по текущему логу цеха —
     * иначе после возврата на этап уведомление и экран расходились.
     *
     * @return Collection<int, Deal>
     */
    private function overdueStages(): Collection
    {
        return Deal::query()
            ->factoryOrders()
            ->open()
            ->with('currentStage')
            ->whereHas('currentStage', fn ($query) => $query->where('estimated_hours', '>', 0))
            ->get()
            ->filter(fn (Deal $order): bool => $order->isStageOverdue())
            ->sortByDesc(fn (Deal $order): float => $order->stageOverdueHours())
            ->values();
    }

    /** @param Collection<int, MaterialStock> $materials */
    private function notifyLowStock(Collection $materials): void
    {
        $names = $materials->take(5)->pluck('name')->implode(', ');
        $more = $materials->count() > 5 ? ' и ещё '.($materials->count() - 5) : '';

        Notification::make()
            ->title('Заканчиваются материалы: '.$materials->count())
            ->body($names.$more)
            ->warning()
            ->actions([
                Action::make('open')
                    ->label('Открыть склад')
                    ->url(MaterialStockResource::getUrl())
                    ->markAsRead(),
            ])
            ->sendToDatabase($this->recipients([UserRole::Admin, UserRole::Manager, UserRole::Master]));

        $this->warn("Отправлено уведомление о {$materials->count()} позициях склада.");
    }

    /** @param Collection<int, Deal> $orders */
    private function notifyOverdue(Collection $orders): void
    {
        $lines = $orders->take(5)
            ->map(fn (Deal $order): string => "{$order->number} — {$order->currentStage?->name} (+{$order->stageOverdueHours()} ч)")
            ->implode('; ');

        Notification::make()
            ->title('Этапы идут дольше норматива: '.$orders->count())
            ->body($lines)
            ->danger()
            ->actions([
                Action::make('open')
                    ->label('Открыть просроченные')
                    ->url(OverdueDeals::getUrl(['mode' => 'stage']))
                    ->markAsRead(),
            ])
            ->sendToDatabase($this->recipients([UserRole::Admin, UserRole::Manager, UserRole::Master]));

        $this->warn("Отправлено уведомление о {$orders->count()} просроченных этапах.");
    }

    /**
     * @param  list<UserRole>  $roles
     * @return Collection<int, User>
     */
    private function recipients(array $roles): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereIn('role', array_map(fn (UserRole $role): string => $role->value, $roles))
            ->get();
    }
}
