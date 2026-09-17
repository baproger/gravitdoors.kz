<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BonusStatus;
use App\Enums\DealEventType;
use App\Models\Bonus;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\Setting;
use App\Models\User;
use App\Support\Money;
use Filament\Notifications\Notification;

/**
 * Автобонус менеджеру: процент от суммы закрытой сделки.
 *
 * Ставка — общая из настроек (меняет администратор), у сотрудника может быть
 * своя. Начисляется один раз на сделку, в месяц закрытия; попадает в ведомость
 * после утверждения (или сразу, если так настроено).
 */
class BonusAccrual
{
    public const SOURCE_DEAL = 'deal_percent';

    public function forCompletedDeal(Deal $deal, ?User $actor = null): ?Bonus
    {
        if ($deal->isFactoryOrder() || ! $deal->manager) {
            return null;
        }

        if (Bonus::query()->where('deal_id', $deal->id)->where('source', self::SOURCE_DEAL)->exists()) {
            return null;
        }

        $manager = $deal->manager;
        $percent = $this->percentFor($manager);
        $amount = round((float) $deal->total_price * $percent / 100, 2);

        if ($percent <= 0 || $amount <= 0) {
            return null;
        }

        $autoApprove = Setting::managerBonusAutoApprove();

        $bonus = Bonus::create([
            'user_id' => $manager->id,
            'month' => now()->format('Y-m'),
            'amount' => $amount,
            'reason' => rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.')."% от сделки {$deal->number} (".Money::format($deal->total_price).')',
            'deal_id' => $deal->id,
            'created_by' => $actor?->id,
            'status' => $autoApprove ? BonusStatus::Approved : BonusStatus::Pending,
            'source' => self::SOURCE_DEAL,
        ]);

        DealEvent::record($deal, DealEventType::Updated,
            "Бонус менеджеру {$manager->name}: ".Money::format($amount).($autoApprove ? ' (утверждён)' : ' (на утверждении)'), $actor);

        Notification::make()
            ->title('Бонус за сделку '.$deal->number)
            ->body(Money::format($amount).($autoApprove ? ' — утверждён, войдёт в ведомость' : ' — ждёт утверждения администратором'))
            ->icon('heroicon-o-gift')
            ->success()
            ->sendToDatabase($manager);

        return $bonus;
    }

    /** Персональная ставка сотрудника, иначе общая из настроек. */
    public function percentFor(User $user): float
    {
        return $user->bonus_percent !== null ? (float) $user->bonus_percent : Setting::managerBonusPercent();
    }
}
