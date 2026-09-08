<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductionStatus;
use App\Enums\UserRole;
use App\Filament\Resources\MaterialStocks\MaterialStockResource;
use App\Models\MaterialStock;
use App\Models\ProductionLog;
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
     * Незакрытые этапы, которые идут дольше своего норматива.
     *
     * @return Collection<int, ProductionLog>
     */
    private function overdueStages(): Collection
    {
        return ProductionLog::query()
            ->with(['stage', 'deal', 'worker'])
            ->whereNull('finished_at')
            ->whereIn('status', [ProductionStatus::Pending->value, ProductionStatus::InProgress->value])
            ->whereHas('stage', fn ($query) => $query->where('estimated_hours', '>', 0))
            ->get()
            ->filter(fn (ProductionLog $log): bool => $log->started_at
                && $log->started_at->diffInMinutes(now()) / 60 > (float) $log->stage->estimated_hours)
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

    /** @param Collection<int, ProductionLog> $logs */
    private function notifyOverdue(Collection $logs): void
    {
        $lines = $logs->take(5)
            ->map(fn (ProductionLog $log): string => "{$log->deal?->number} — {$log->stage->name}")
            ->implode('; ');

        Notification::make()
            ->title('Этапы идут дольше норматива: '.$logs->count())
            ->body($lines)
            ->danger()
            ->sendToDatabase($this->recipients([UserRole::Admin, UserRole::Manager, UserRole::Master]));

        $this->warn("Отправлено уведомление о {$logs->count()} просроченных этапах.");
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
