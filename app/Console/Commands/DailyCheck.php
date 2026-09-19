<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Filament\Pages\OverdueDeals;
use App\Filament\Resources\MaterialStocks\MaterialStockResource;
use App\Models\Deal;
use App\Models\MaterialStock;
use App\Models\User;
use App\Services\AccessControl;
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
        $measurementsToday = Deal::query()->measurementToday()->with('manager')->get();
        $measurementsOverdue = Deal::query()->measurementOverdue()->with('manager')->get();

        $this->table(
            ['Проверка', 'Найдено'],
            [
                ['Материалы ниже минимума', $lowStock->count()],
                ['Этапы дольше норматива', $overdue->count()],
                ['Замеры сегодня', $measurementsToday->count()],
                ['Замеры просрочены', $measurementsOverdue->count()],
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

        if ($measurementsToday->isNotEmpty()) {
            $this->notifyMeasurementsToday($measurementsToday);
        }

        if ($measurementsOverdue->isNotEmpty()) {
            $this->notifyMeasurementsOverdue($measurementsOverdue);
        }

        if ($lowStock->isEmpty() && $overdue->isEmpty() && $measurementsToday->isEmpty() && $measurementsOverdue->isEmpty()) {
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
            ->sendToDatabase($this->recipients(Permission::WorkMaterials));

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
            ->sendToDatabase($this->recipients(Permission::WorkOverdue));

        $this->warn("Отправлено уведомление о {$orders->count()} просроченных этапах.");
    }

    /**
     * Утренний список выездов замерщикам и ответственным менеджерам: время,
     * клиент, адрес, телефон — всё, что нужно, чтобы поехать.
     *
     * @param  Collection<int, Deal>  $deals
     */
    private function notifyMeasurementsToday(Collection $deals): void
    {
        $lines = $deals->map(fn (Deal $deal): string => trim(
            $deal->measured_at->format('H:i').' — '.$deal->clientTitle()
            .(filled($deal->client_address) ? ', '.$deal->client_address : '')
            .(filled($deal->client_phone) ? ' · '.$deal->client_phone : '')
        ))->implode('; ');

        $recipients = User::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user): bool => $user->role->doesSurveys() || $deals->contains('manager_id', $user->id))
            ->values();

        Notification::make()
            ->title('Замеры сегодня: '.$deals->count())
            ->body($lines)
            ->icon('heroicon-o-map-pin')
            ->info()
            ->sendToDatabase($recipients);

        $this->line("Замеры на сегодня ({$deals->count()}) отправлены {$recipients->count()} сотрудникам.");
    }

    /**
     * Просроченный замер — директору (ведёт всю воронку продаж) и ответственному
     * менеджеру: дата прошла, а сделка так и стоит до договора.
     *
     * @param  Collection<int, Deal>  $deals
     */
    private function notifyMeasurementsOverdue(Collection $deals): void
    {
        $lines = $deals->take(5)
            ->map(fn (Deal $deal): string => "{$deal->number} · {$deal->clientTitle()} — замер {$deal->measured_at->format('d.m.Y H:i')}, +{$deal->measurementOverdueDays()} дн.")
            ->implode('; ');
        $more = $deals->count() > 5 ? ' и ещё '.($deals->count() - 5) : '';

        $recipients = User::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user): bool => AccessControl::allows($user, Permission::WorkSalesKanban, AccessLevel::Full)
                || $deals->contains('manager_id', $user->id))
            ->values();

        Notification::make()
            ->title('Замеры просрочены: '.$deals->count())
            ->body($lines.$more)
            ->danger()
            ->actions([
                Action::make('open')
                    ->label('Открыть просроченные')
                    ->url(OverdueDeals::getUrl(['mode' => 'measurement']))
                    ->markAsRead(),
            ])
            ->sendToDatabase($recipients);

        $this->warn("Просроченные замеры ({$deals->count()}) отправлены {$recipients->count()} сотрудникам.");
    }

    /**
     * Кому уходит сводка: тем, кому открыт соответствующий раздел.
     * Список ролей здесь не зашит — иначе он разошёлся бы с матрицей доступа.
     *
     * @return Collection<int, User>
     */
    private function recipients(Permission $permission): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user): bool => AccessControl::allows($user, $permission))
            ->values();
    }
}
