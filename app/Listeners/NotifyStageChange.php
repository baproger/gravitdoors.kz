<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Events\DealStageChanged;
use App\Filament\Resources\Deals\DealResource;
use App\Models\User;
use App\Services\AccessControl;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Сделку двинули — об этом узнают те, кому она важна, а не только история.
 *
 * Две группы получателей. Директор (все, кому видна вся воронка) — чтобы
 * замечать движение без открытия карточек. Ответственный менеджер — когда
 * сделку двинул не он: завод закрыл наряд и заказ стал «Готово к отгрузке»,
 * или директор перевёл её сам. Автор переноса себе не пишет.
 */
class NotifyStageChange
{
    public function handle(DealStageChanged $event): void
    {
        // Только воронка продаж: наряд проходит 13 этапов цеха, и уведомление
        // на каждый превратило бы колокольчик в ленту станка.
        if ($event->deal->isFactoryOrder()) {
            return;
        }

        $recipients = $this->overseers($event->actor)
            ->when($this->manager($event), fn (Collection $users, User $manager) => $users->push($manager))
            ->unique('id')
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        $deal = $event->deal;
        $move = $event->from
            ? "«{$event->from->name}» → «{$event->to->name}»"
            : "«{$event->to->name}»";

        // Завод закрыл наряд — сделка ушла с этапа передачи в цех сама. Для
        // менеджера это не «кто-то перенёс», а «заказ готов, пора к клиенту».
        $fromFactory = (bool) $event->from?->triggers_production;

        $body = $fromFactory
            ? "Завод закончил заказ: {$move}"
            : $move.($event->actor ? ' · перенёс '.$event->actor->name : '');

        Notification::make()
            ->title($deal->number.' · '.$deal->clientTitle())
            ->body($body)
            ->icon($fromFactory ? 'heroicon-o-check-badge' : 'heroicon-o-arrow-right-circle')
            ->color($fromFactory ? 'success' : 'info')
            ->actions([DealResource::openAction($deal)])
            ->sendToDatabase($recipients);
    }

    /**
     * Кому видна вся воронка продаж — обычно это директор. Сам автор переноса
     * исключён: он только что нажал кнопку и всё про неё знает.
     *
     * @return Collection<int, User>
     */
    private function overseers(?User $actor): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->when($actor, fn ($query) => $query->whereKeyNot($actor->getKey()))
            ->get()
            ->filter(fn (User $user): bool => AccessControl::allows($user, Permission::WorkSalesKanban, AccessLevel::Full))
            ->values();
    }

    /** Ответственный менеджер, если сделку двинул кто-то другой. */
    private function manager(DealStageChanged $event): ?User
    {
        $manager = $event->deal->manager;

        if (! $manager || ! $manager->is_active) {
            return null;
        }

        if ($event->actor && $manager->is($event->actor)) {
            return null;
        }

        return $manager;
    }
}
