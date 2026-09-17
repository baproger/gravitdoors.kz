<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\DealEventType;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\DealPayment;
use App\Services\CashLedger;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Держит deals.prepayment равной сумме платежей и пишет оплаты в историю.
 *
 * Сумма хранится в сделке, а не считается на лету: по ней работают регламент
 * этапов, остаток к оплате и списки — пересчёт в каждом из этих мест дал бы
 * лишний запрос на каждую строку.
 */
class DealPaymentObserver
{
    public function creating(DealPayment $payment): void
    {
        $payment->user_id ??= auth()->id();
    }

    /**
     * Сумма платежей не больше суммы сделки — правило сервера, а не только формы:
     * форма сравнивает с суммой до пересчёта спецификации, и лишний платёж мог
     * проскочить вместе с удалением двери в том же сохранении.
     */
    public function saving(DealPayment $payment): void
    {
        $deal = $payment->deal;
        $total = (float) $deal->total_price;

        if ($total <= 0.0) {
            return;
        }

        $others = (float) $deal->payments()->whereKeyNot($payment->getKey())->sum('amount');

        if (round($others + (float) $payment->amount, 2) > round($total, 2)) {
            throw ValidationException::withMessages([
                'payments' => 'Сумма платежей '.Money::format($others + (float) $payment->amount)
                    .' больше суммы сделки '.Money::format($total).'.',
            ]);
        }
    }

    public function created(DealPayment $payment): void
    {
        $this->sync($payment->deal);
        app(CashLedger::class)->syncPayment($payment);

        DealEvent::record(
            $payment->deal,
            DealEventType::Payment,
            sprintf(
                'Оплата %s · %s · %s, чек приложен',
                Money::format((float) $payment->amount),
                $payment->method->getLabel(),
                $payment->paid_at->format('d.m.Y'),
            ),
        );
    }

    public function updated(DealPayment $payment): void
    {
        $this->sync($payment->deal);
        app(CashLedger::class)->syncPayment($payment);

        if ($payment->wasChanged(['amount', 'method', 'paid_at', 'receipt_path'])) {
            DealEvent::record(
                $payment->deal,
                DealEventType::Payment,
                'Изменён платёж от '.$payment->paid_at->format('d.m.Y').': '.Money::format((float) $payment->amount),
            );
        }
    }

    public function deleted(DealPayment $payment): void
    {
        app(CashLedger::class)->forget($payment);

        $deal = Deal::query()->find($payment->deal_id);

        if (! $deal) {
            return;
        }

        $this->sync($deal);

        DealEvent::record(
            $deal,
            DealEventType::Payment,
            'Удалён платёж '.Money::format((float) $payment->amount).' от '.$payment->paid_at->format('d.m.Y'),
        );
    }

    private function sync(Deal $deal): void
    {
        // Тихо: у платежа своя запись в истории, а правка поля «Предоплата»
        // от автоматики только задвоила бы её.
        $deal->forceFill([
            'prepayment' => round((float) $deal->payments()->sum('amount'), 2),
        ])->saveQuietly();
    }
}
